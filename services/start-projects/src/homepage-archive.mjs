import { crc32 } from 'node:zlib';

/** The archive contains only the generated, fixed-name homepage files. */
export function homepageArchive(files) {
  const local = [];
  const directory = [];
  let offset = 0;
  for (const [filename, text] of Object.entries(files)) {
    const name = Buffer.from(filename, 'utf8');
    const content = Buffer.from(text, 'utf8');
    const checksum = crc32(content);
    const record = Buffer.alloc(30);
    record.writeUInt32LE(0x04034b50, 0);
    record.writeUInt16LE(20, 4);
    record.writeUInt16LE(0x800, 6);
    record.writeUInt32LE(checksum, 14);
    record.writeUInt32LE(content.length, 18);
    record.writeUInt32LE(content.length, 22);
    record.writeUInt16LE(name.length, 26);
    local.push(record, name, content);

    const entry = Buffer.alloc(46);
    entry.writeUInt32LE(0x02014b50, 0);
    entry.writeUInt16LE(20, 4);
    entry.writeUInt16LE(20, 6);
    entry.writeUInt16LE(0x800, 8);
    entry.writeUInt32LE(checksum, 16);
    entry.writeUInt32LE(content.length, 20);
    entry.writeUInt32LE(content.length, 24);
    entry.writeUInt16LE(name.length, 28);
    entry.writeUInt32LE(offset, 42);
    directory.push(entry, name);
    offset += record.length + name.length + content.length;
  }
  const central = Buffer.concat(directory);
  const end = Buffer.alloc(22);
  end.writeUInt32LE(0x06054b50, 0);
  end.writeUInt16LE(directory.length / 2, 8);
  end.writeUInt16LE(directory.length / 2, 10);
  end.writeUInt32LE(central.length, 12);
  end.writeUInt32LE(offset, 16);
  return Buffer.concat([...local, central, end]);
}
