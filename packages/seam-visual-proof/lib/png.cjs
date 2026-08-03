const zlib = require('node:zlib');

const SIGNATURE = Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]);
const MAX_PIXELS = 50_000_000;
let crcTable;

function table() {
	if (crcTable) return crcTable;
	crcTable = Array.from({ length: 256 }, (_, index) => {
		let value = index;
		for (let bit = 0; bit < 8; bit += 1) value = value & 1 ? 0xedb88320 ^ (value >>> 1) : value >>> 1;
		return value >>> 0;
	});
	return crcTable;
}

function crc32(buffer) {
	let value = 0xffffffff;
	const values = table();
	for (const byte of buffer) value = values[(value ^ byte) & 0xff] ^ (value >>> 8);
	return (value ^ 0xffffffff) >>> 0;
}

function chunk(type, data) {
	const name = Buffer.from(type, 'ascii');
	const result = Buffer.alloc(12 + data.length);
	result.writeUInt32BE(data.length, 0);
	name.copy(result, 4);
	data.copy(result, 8);
	result.writeUInt32BE(crc32(Buffer.concat([name, data])), 8 + data.length);
	return result;
}

function paeth(left, up, upperLeft) {
	const estimate = left + up - upperLeft;
	const leftDistance = Math.abs(estimate - left);
	const upDistance = Math.abs(estimate - up);
	const upperLeftDistance = Math.abs(estimate - upperLeft);
	return leftDistance <= upDistance && leftDistance <= upperLeftDistance ? left : upDistance <= upperLeftDistance ? up : upperLeft;
}

function decodePng(buffer) {
	if (!Buffer.isBuffer(buffer) || buffer.length < 33 || !buffer.subarray(0, 8).equals(SIGNATURE)) throw new Error('Input is not a PNG image.');
	let offset = 8;
	let width = 0;
	let height = 0;
	let bitDepth = 0;
	let colorType = -1;
	let interlace = 0;
	const compressed = [];
	while (offset + 12 <= buffer.length) {
		const length = buffer.readUInt32BE(offset);
		const type = buffer.toString('ascii', offset + 4, offset + 8);
		const data = buffer.subarray(offset + 8, offset + 8 + length);
		if (offset + 12 + length > buffer.length) throw new Error('PNG chunk exceeds input length.');
		if (type === 'IHDR') {
			width = data.readUInt32BE(0); height = data.readUInt32BE(4); bitDepth = data[8]; colorType = data[9]; interlace = data[12];
		} else if (type === 'IDAT') compressed.push(data);
		else if (type === 'IEND') break;
		offset += 12 + length;
	}
	if (!width || !height || width * height > MAX_PIXELS || bitDepth !== 8 || interlace !== 0 || ![0, 2, 4, 6].includes(colorType)) throw new Error(`Unsupported PNG format: ${width}x${height}, depth ${bitDepth}, color ${colorType}, interlace ${interlace}.`);
	const channels = { 0: 1, 2: 3, 4: 2, 6: 4 }[colorType];
	const stride = width * channels;
	const expectedBytes = (stride + 1) * height;
	const inflated = zlib.inflateSync(Buffer.concat(compressed), { maxOutputLength: expectedBytes });
	if (inflated.length !== expectedBytes) throw new Error('PNG scanline length is inconsistent with IHDR.');
	const raw = Buffer.alloc(stride * height);
	let sourceOffset = 0;
	for (let y = 0; y < height; y += 1) {
		const filter = inflated[sourceOffset++];
		for (let x = 0; x < stride; x += 1) {
			const encoded = inflated[sourceOffset++];
			const rawIndex = y * stride + x;
			const left = x >= channels ? raw[rawIndex - channels] : 0;
			const up = y > 0 ? raw[rawIndex - stride] : 0;
			const upperLeft = y > 0 && x >= channels ? raw[rawIndex - stride - channels] : 0;
			let predictor = 0;
			if (filter === 1) predictor = left;
			else if (filter === 2) predictor = up;
			else if (filter === 3) predictor = Math.floor((left + up) / 2);
			else if (filter === 4) predictor = paeth(left, up, upperLeft);
			else if (filter !== 0) throw new Error(`Unsupported PNG filter ${filter}.`);
			raw[rawIndex] = (encoded + predictor) & 0xff;
		}
	}
	const data = Buffer.alloc(width * height * 4);
	for (let pixel = 0; pixel < width * height; pixel += 1) {
		const source = pixel * channels;
		const target = pixel * 4;
		if (colorType === 0) data[target] = data[target + 1] = data[target + 2] = raw[source];
		else if (colorType === 2 || colorType === 6) { data[target] = raw[source]; data[target + 1] = raw[source + 1]; data[target + 2] = raw[source + 2]; }
		else { data[target] = data[target + 1] = data[target + 2] = raw[source]; }
		data[target + 3] = colorType === 4 ? raw[source + 1] : colorType === 6 ? raw[source + 3] : 255;
	}
	return { width, height, data };
}

function encodePng({ width, height, data }) {
	if (!Number.isInteger(width) || !Number.isInteger(height) || width < 1 || height < 1 || width * height > MAX_PIXELS || !Buffer.isBuffer(data) || data.length !== width * height * 4) throw new Error('RGBA PNG input dimensions are invalid.');
	const header = Buffer.alloc(13);
	header.writeUInt32BE(width, 0); header.writeUInt32BE(height, 4);
	header[8] = 8; header[9] = 6; header[10] = 0; header[11] = 0; header[12] = 0;
	const scanlines = Buffer.alloc((width * 4 + 1) * height);
	for (let y = 0; y < height; y += 1) {
		const destination = y * (width * 4 + 1);
		scanlines[destination] = 0;
		data.copy(scanlines, destination + 1, y * width * 4, (y + 1) * width * 4);
	}
	return Buffer.concat([SIGNATURE, chunk('IHDR', header), chunk('IDAT', zlib.deflateSync(scanlines, { level: 9 })), chunk('IEND', Buffer.alloc(0))]);
}

module.exports = { crc32, decodePng, encodePng };
