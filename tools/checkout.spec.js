// Block-checkout run against a WordPress Playground store built from playground/blueprint.json.
//
// Proves what the Store API case (suite F1) cannot: that the totals a shopper sees in the block
// cart/checkout carry the split shipping tax. Runs the reference carts through the UI and reads the
// numbers off the page.
//
//   npm i -D @playwright/test            (Chromium must be installed: npx playwright install chromium)
//   WCTC_BLUEPRINT=playground/blueprint.json npx playwright test tools/checkout.spec.js
//
// Set WCTC_BASE to the store's URL (e.g. https://playground.wordpress.net/scope:xyz) to run against a
// Playground you already booted, and WCTC_PLAYGROUND_URL to a full Playground launch URL
// (?blueprint-url=…) to let the test boot one.

const { test, expect } = require( '@playwright/test' );
const fs = require( 'fs' );

const carts = [
	{
		name: 'NYC hoodie + book: shipping follows the goods, split by value',
		address: { country: 'US', state: 'NY', city: 'New York', postcode: '10001' },
		items: [ 'hoodie', 'book' ],
		expect: { tax: '2.05', shipping_tax: '0.27' },
	},
	{
		name: 'Arizona hoodie + book: shipping exempt on its own line',
		address: { country: 'US', state: 'AZ', city: 'Phoenix', postcode: '85001' },
		items: [ 'hoodie', 'book' ],
		expect: { tax: '3.64', shipping_tax: '0.00' },
	},
	{
		name: 'UK book + mug: zero-rated book, VAT on the mug and its share of shipping',
		address: { country: 'GB', state: '', city: 'London', postcode: 'SW1A 1AA' },
		items: [ 'book', 'mug' ],
		expect: { tax: '2.67', shipping_tax: '0.67' },
	},
	{
		name: 'Minnesota jacket + blender: split by weight',
		address: { country: 'US', state: 'MN', city: 'Minneapolis', postcode: '55401' },
		items: [ 'jacket', 'blender' ],
		expect: { tax: '3.27', shipping_tax: '0.52' },
	},
];

test.describe.configure( { mode: 'serial' } );

let base = process.env.WCTC_BASE || '';
let page;
let products = {};

test.beforeAll( async ( { browser } ) => {
	test.setTimeout( 6 * 60 * 1000 );
	page = await browser.newPage();
	if ( ! base ) {
		const launch = process.env.WCTC_PLAYGROUND_URL || ( 'https://playground.wordpress.net/#' + encodeURIComponent( fs.readFileSync( process.env.WCTC_BLUEPRINT || 'playground/blueprint.json', 'utf8' ) ) );
		await page.goto( launch );
		// Playground renders WordPress in a nested iframe; wait for it to land on a scope: URL.
		await expect.poll( async () => {
			try {
				return await page.evaluate( () => document.querySelector( 'iframe' ).contentWindow.document.querySelector( 'iframe' ).contentWindow.location.href );
			} catch ( e ) {
				return '';
			}
		}, { timeout: 5 * 60 * 1000, intervals: [ 3000 ] } ).toMatch( /scope:[^/]+\/wp-admin/ );
		const href = await page.evaluate( () => document.querySelector( 'iframe' ).contentWindow.document.querySelector( 'iframe' ).contentWindow.location.href );
		base = href.replace( /\/wp-admin.*$/, '' );
	}
	// Product IDs: playground/setup.php writes them to /wctc-products.json.
	const res = await page.request.get( base + '/wctc-products.json' ).catch( () => null );
	if ( res && res.ok() ) {
		products = await res.json();
	} else {
		// Fall back to the shop page: product links carry their slugs; add-to-cart works by ID.
		await page.goto( base + '/shop/' );
		for ( const li of await page.locator( 'li.product' ).all() ) {
			const name = ( await li.locator( '.woocommerce-loop-product__title, h2' ).first().innerText() ).trim().toLowerCase();
			const id = await li.locator( 'a[data-product_id]' ).first().getAttribute( 'data-product_id' );
			for ( const key of [ 'hoodie', 'book', 'mug', 'jacket', 'blender', 'coat' ] ) {
				if ( name.includes( key ) && ! products[ key ] ) {
					products[ key ] = id;
				}
			}
		}
	}
	for ( const key of [ 'hoodie', 'book', 'mug', 'jacket', 'blender' ] ) {
		expect( products[ key ], `product id for ${ key }` ).toBeTruthy();
	}
} );

// The checkout block lists one "taxes" row per rate when taxes are itemised, or one "Taxes" row otherwise.
async function taxTotal( page ) {
	let sum = 0;
	for ( const v of await page.locator( '.wc-block-components-totals-taxes .wc-block-components-totals-item__value' ).all() ) {
		sum += Number( ( await v.innerText() ).replace( /[^0-9.]/g, '' ) || 0 );
	}
	return sum.toFixed( 2 );
}

for ( const cart of carts ) {
	test( cart.name, async () => {
		// Empty the cart, add the items, open the block checkout.
		await page.goto( base + '/cart/' );
		for ( const btn of await page.locator( '.wc-block-cart-item__remove-link' ).all() ) {
			await btn.click();
		}
		for ( const key of cart.items ) {
			await page.goto( base + '/?add-to-cart=' + products[ key ] );
		}
		await page.goto( base + '/checkout/' );
		await page.locator( '#shipping-first_name, #billing-first_name' ).first().waitFor();

		// Address. The checkout block collects the shipping address first (ids shipping-*); a store that
		// only collects billing uses billing-*. State is a <select> for countries with a state list.
		const prefix = ( await page.locator( '#shipping-first_name' ).count() ) ? 'shipping' : 'billing';
		await page.fill( `#${ prefix }-first_name`, 'Suite' );
		await page.fill( `#${ prefix }-last_name`, 'Buyer' );
		await page.fill( `#${ prefix }-address_1`, '1 Test St' );
		await page.selectOption( `#${ prefix }-country`, cart.address.country ).catch( async () => {
			await page.fill( `#${ prefix }-country`, cart.address.country );
		} );
		if ( cart.address.state ) {
			await page.selectOption( `#${ prefix }-state`, cart.address.state ).catch( async () => {
				await page.fill( `#${ prefix }-state`, cart.address.state );
			} );
		}
		await page.fill( `#${ prefix }-city`, cart.address.city );
		await page.fill( `#${ prefix }-postcode`, cart.address.postcode );
		await page.fill( '#email', 'suite@example.com' ).catch( () => {} );

		// Pick Flat rate and let the totals settle.
		const flat = page.locator( '.wc-block-components-shipping-rates-control label', { hasText: 'Flat rate' } ).first();
		if ( await flat.count() ) {
			await flat.click();
		}
		await page.waitForTimeout( 1500 );
		await expect( page.locator( '.wc-block-components-totals-item__value' ).first() ).toBeVisible();

		// Totals block: the tax rows add up to the order's total tax. Shipping tax is not shown on its own
		// in the block, so it is read from the Store API for the same session.
		await expect.poll( () => taxTotal( page ), { timeout: 15000 } ).toBe( cart.expect.tax );
		const api = await page.evaluate( async ( b ) => {
			const r = await fetch( b + '/wp-json/wc/store/v1/cart', { credentials: 'include' } );
			const j = await r.json();
			return { tax: ( j.totals.total_tax / 100 ).toFixed( 2 ), shipping_tax: ( j.totals.total_shipping_tax / 100 ).toFixed( 2 ) };
		}, base );
		expect( api.shipping_tax ).toBe( cart.expect.shipping_tax );
		expect( api.tax ).toBe( cart.expect.tax );
	} );
}
