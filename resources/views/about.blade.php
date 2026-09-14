<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="About BuyTheWay, a multi-seller online marketplace.">

    <title>About Us — BuyTheWay</title>

    <link rel="icon" href="/favicon.svg" type="image/svg+xml">

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Bodoni+Moda:wght@600&family=Inter:wght@400;500;600&display=swap');

        :root {
            --bg: #f7f7f4;
            --ink: #142521;
            --muted: #58655f;
            --accent: #205c50;
            --border: #cfdad3;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--ink);
            font-family: 'Inter', system-ui, sans-serif;
            font-size: 16px;
            line-height: 1.7;
        }

        a { color: var(--accent); }

        header {
            border-bottom: 1px solid var(--border);
            padding: 1rem 1.5rem;
        }

        header a.brand {
            font-family: 'Bodoni Moda', serif;
            font-weight: 600;
            font-size: 1.1rem;
            color: var(--ink);
            text-decoration: none;
        }

        main {
            max-width: 640px;
            margin: 0 auto;
            padding: 3rem 1.5rem 4rem;
        }

        h1 {
            font-family: 'Bodoni Moda', serif;
            font-weight: 600;
            font-size: clamp(1.8rem, 4vw, 2.4rem);
            letter-spacing: -0.02em;
            margin: 0 0 1.5rem;
        }

        p {
            color: var(--muted);
            margin: 0 0 1.2rem;
        }

        .back {
            display: inline-block;
            margin-top: 1rem;
            font-weight: 500;
            font-size: 0.92rem;
        }
    </style>
</head>
<body>
    <header>
        <a href="/" class="brand">BuyTheWay</a>
    </header>

    <main>
        <h1>About BuyTheWay</h1>

        <p>BuyTheWay is a multi-seller online marketplace. Rather than running its own single storefront, it brings together independent sellers across categories like apparel, electronics, home goods, and pet supplies, so buyers can discover and compare items from different sellers in one place.</p>

        <p>Each seller manages their own listings, pricing, and inventory. Buyers can browse the catalog, add items from different sellers to a single cart, and check out in one flow. Sellers register and go through an approval step before their listings become publicly visible.</p>

        <p>BuyTheWay is under active development. Some features described in the shopping experience, such as buyer order tracking, are still being built out.</p>

        <a href="/" class="back">&larr; Back to BuyTheWay</a>
    </main>
</body>
</html>
