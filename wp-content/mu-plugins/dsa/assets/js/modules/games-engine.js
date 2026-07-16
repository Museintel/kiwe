function clearCanvas( context, canvas ) {
	context.clearRect( 0, 0, canvas.width, canvas.height );
}

function drawGround( context, canvas, color ) {
	context.strokeStyle = color;
	context.lineWidth = 3;
	context.beginPath();
	context.moveTo( 36, 366 );
	context.lineTo( canvas.width - 36, 366 );
	context.stroke();
}

function intersects( ax, ay, aw, ah, bx, by, bw, bh ) {
	return ax < bx + bw && ax + aw > bx && ay < by + bh && ay + ah > by;
}

function dinosaurJump( canvas, colors ) {
	const context = canvas.getContext( '2d' );
	const dino = { x: 90, y: 318, w: 42, h: 58, vy: 0, grounded: true };
	const hurdles = [ { x: 840, w: 26, h: 58 }, { x: 1180, w: 34, h: 42 } ];
	let speed = 5.2;
	let score = 0;

	return {
		title: 'Dinosaur Jump',
		score: 0,
		over: false,
		input: function () {
			if ( dino.grounded ) {
				dino.vy = -15.5;
				dino.grounded = false;
			}
		},
		update: function () {
			score += 1;
			speed += 0.003;
			dino.vy += 0.72;
			dino.y += dino.vy;
			if ( dino.y >= 318 ) {
				dino.y = 318;
				dino.vy = 0;
				dino.grounded = true;
			}
			hurdles.forEach( function ( hurdle ) {
				hurdle.x -= speed;
				if ( hurdle.x < -80 ) {
					hurdle.x = 960 + Math.random() * 360;
					hurdle.h = 36 + Math.random() * 34;
				}
				if ( intersects( dino.x, dino.y, dino.w, dino.h, hurdle.x, 360 - hurdle.h, hurdle.w, hurdle.h ) ) this.over = true;
			}, this );
			this.score = Math.floor( score / 5 );
		},
		draw: function () {
			clearCanvas( context, canvas );
			drawGround( context, canvas, colors.hero );
			context.fillStyle = colors.hero;
			context.font = '900 92px system-ui, sans-serif';
			context.fillText( 'JUMP', 36, 116 );
			context.fillStyle = colors.active;
			context.globalAlpha = 0.62;
			context.fillRect( dino.x, dino.y, dino.w, dino.h );
			context.fillRect( dino.x + 27, dino.y - 18, 28, 28 );
			context.globalAlpha = 1;
			hurdles.forEach( function ( hurdle ) {
				context.fillStyle = colors.active;
				context.globalAlpha = 0.48;
				context.fillRect( hurdle.x, 360 - hurdle.h, hurdle.w, hurdle.h );
				context.globalAlpha = 1;
			} );
		},
	};
}

function starShooter( canvas, colors ) {
	const context = canvas.getContext( '2d' );
	const ship = { x: 460, y: 338, w: 44, h: 44 };
	const shots = [];
	const stars = [];
	let tick = 0;
	let score = 0;

	return {
		title: 'Star Shooter',
		score: 0,
		over: false,
		input: function () { shots.push( { x: ship.x + 20, y: ship.y } ); },
		move: function ( direction ) { ship.x = Math.max( 24, Math.min( canvas.width - 68, ship.x + direction * 34 ) ); },
		update: function () {
			tick += 1;
			if ( tick % 34 === 0 ) stars.push( { x: 40 + Math.random() * ( canvas.width - 80 ), y: -20, r: 14 + Math.random() * 10 } );
			shots.forEach( function ( shot ) { shot.y -= 9; } );
			stars.forEach( function ( star ) { star.y += 2.6 + score * 0.004; } );
			for ( let s = stars.length - 1; s >= 0; s-- ) {
				for ( let b = shots.length - 1; b >= 0; b-- ) {
					if ( Math.abs( stars[ s ].x - shots[ b ].x ) < stars[ s ].r + 8 && Math.abs( stars[ s ].y - shots[ b ].y ) < stars[ s ].r + 8 ) {
						stars.splice( s, 1 );
						shots.splice( b, 1 );
						score += 12;
						break;
					}
				}
			}
			this.over = tick > 1450 || stars.some( function ( star ) { return star.y > canvas.height - 42; } );
			this.score = score + Math.floor( tick / 14 );
		},
		draw: function () {
			clearCanvas( context, canvas );
			drawGround( context, canvas, colors.hero );
			context.fillStyle = colors.hero;
			context.font = '900 86px system-ui, sans-serif';
			context.fillText( 'SHOOT', 36, 112 );
			context.fillStyle = colors.active;
			context.globalAlpha = 0.62;
			context.beginPath();
			context.moveTo( ship.x + 22, ship.y );
			context.lineTo( ship.x + ship.w, ship.y + ship.h );
			context.lineTo( ship.x, ship.y + ship.h );
			context.closePath();
			context.fill();
			context.globalAlpha = 1;
			shots.forEach( function ( shot ) {
				context.fillStyle = colors.hover;
				context.fillRect( shot.x, shot.y, 5, 18 );
			} );
			stars.forEach( function ( star ) {
				context.fillStyle = colors.active;
				context.globalAlpha = 0.44;
				context.beginPath();
				context.arc( star.x, star.y, star.r, 0, Math.PI * 2 );
				context.fill();
				context.globalAlpha = 1;
			} );
		},
	};
}

export function createGame( id, canvas, colors ) {
	if ( ! canvas || ! canvas.getContext ) throw new Error( 'Game canvas is unavailable.' );
	colors = Object.assign( { hero: 'rgba(20,24,34,0.18)', active: '#8f8f98', hover: '#24c6a1' }, colors || {} );
	return id === 'star' ? starShooter( canvas, colors ) : dinosaurJump( canvas, colors );
}
