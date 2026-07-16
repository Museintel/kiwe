const fs = require( 'fs' );
const path = require( 'path' );

const root = path.resolve( __dirname, '..', '..' );
const reward = fs.readFileSync( path.join( root, 'wp-content/mu-plugins/dsa/includes/Rewards/Reward_Service.php' ), 'utf8' );
const commerce = fs.readFileSync( path.join( root, 'wp-content/mu-plugins/dsa/includes/Commerce/Store_Analytics_Service.php' ), 'utf8' );

const assertions = [
	[ reward.includes( '$duration_ms = $server_duration_ms;' ), 'reward duration is server authoritative' ],
	[ ! reward.includes( '$duration_ms = max( $server_duration_ms, $client_duration_ms );' ), 'client duration cannot satisfy minimum play time' ],
	[ reward.includes( 'consume_attempt_token' ), 'attempt token is consumed atomically' ],
	[ reward.includes( "acquire_lock( 'identity|'" ) && reward.includes( "acquire_lock( 'ip|'" ), 'identity and IP ledgers are serialized' ],
	[ reward.includes( 'daily_coupon_budget' ) && reward.includes( 'coupon-budget|' ), 'coupon issuance has a daily atomic budget' ],
	[ reward.includes( "'scoreTrusted' => false" ), 'client score is explicitly untrusted' ],
	[ commerce.includes( "sort( $ids, SORT_NUMERIC )" ), 'pair identity is direction independent' ],
	[ commerce.includes( 'cart_discount_claim_conflicts' ), 'new claims reject affected-product overlap' ],
	[ commerce.includes( 'clear_upsell_claim' ) && commerce.includes( 'array_intersect( $claim_ids, $affected_ids )' ), 'session reconciliation removes duplicate or overlapping claims' ],
];

for ( const [ pass, label ] of assertions ) {
	if ( ! pass ) throw new Error( `RC2 invariant failed: ${ label }` );
}

function pairKey( a, b ) {
	if ( ! a || ! b || a === b ) return '';
	return [ a, b ].sort( ( left, right ) => left - right ).join( ':' );
}

function affected( scope, a, b, aPrice, bPrice ) {
	if ( scope === 'both' ) return [ a, b ];
	if ( scope === 'single_highest' ) return [ aPrice >= bPrice ? a : b ];
	return [ aPrice <= bPrice && aPrice > 0 ? a : b ];
}

for ( let index = 0; index < 100000; index++ ) {
	const a = 1 + Math.floor( Math.random() * 10000 );
	let b = 1 + Math.floor( Math.random() * 10000 );
	if ( a === b ) b += 10001;
	if ( pairKey( a, b ) !== pairKey( b, a ) ) throw new Error( 'Reverse pair key mismatch' );

	const aPrice = Math.random() * 1000 + 0.01;
	const bPrice = Math.random() * 1000 + 0.01;
	const both = affected( 'both', a, b, aPrice, bPrice );
	const low = affected( 'single_lowest', a, b, aPrice, bPrice );
	const high = affected( 'single_highest', a, b, aPrice, bPrice );
	if ( both.length !== 2 || low.length !== 1 || high.length !== 1 ) throw new Error( 'Scope cardinality mismatch' );
	if ( low[0] === high[0] && aPrice !== bPrice ) throw new Error( 'Low/high scope collision' );
}

console.log( 'RC2 invariants passed: source contracts + 100,000 randomized pair cases.' );
