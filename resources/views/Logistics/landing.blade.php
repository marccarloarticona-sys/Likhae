<!doctype html>
<html lang="en">
<head>
    <x-likhae-favicon />
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="The LIKHAE workspace for logistics centers and riders. Coordinate parcel intake, sorting, pickups, and deliveries in one place.">
    <title>Logistics &amp; Rider Portal | LIKHAE</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite('resources/css/logistic/landing.css')
</head>
<body class="lp-page">
    <a class="lp-skip" href="#main-content">Skip to content</a>

    <header class="lp-header lp-container">
        <a href="{{ route('logistics.home') }}" class="lp-brand" aria-label="LIKHAE Logistics home">
            <x-likhae-logo context="Logistics" />
            <span class="lp-brand__context" aria-hidden="true">Logistics<br>&amp; Riders</span>
        </a>
        <nav class="lp-nav" aria-label="Portal navigation">
            <a href="#workspaces">Who it's for</a>
            <a href="#how-it-works">How it works</a>
            <a href="{{ route('home') }}" class="lp-nav__marketplace">
                Marketplace
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 17 17 7M7 7h10v10" /></svg>
            </a>
        </nav>
        <a href="{{ route('logistics.login') }}" class="lp-button lp-button--small lp-button--primary">
            Sign in
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6" /></svg>
        </a>
    </header>

    <main id="main-content" class="lp-container">
        <section class="lp-hero" aria-labelledby="lp-hero-title">
            <div class="lp-hero__scene">
                <img class="lp-hero__image" src="{{ asset('images/logistics image.jpg') }}" alt="" width="1424" height="752" fetchpriority="high" decoding="async">
                <div class="lp-hero__content">
                    <span class="lp-eyebrow">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 7 9-4 9 4v10l-9 4-9-4V7Zm0 0 9 4 9-4M12 11v10M7.5 5 17 9" /></svg>
                        The LIKHAE logistics network
                    </span>
                    <h1 id="lp-hero-title">Good things,<br><em>on the move.</em></h1>
                    <p class="lp-hero__description">From the first pickup to the final doorstep. Bring your parcels, people, and daily deliveries together in one workspace.</p>
                    <div class="lp-hero__actions">
                        <a href="{{ route('logistics.login') }}" class="lp-button lp-button--primary">
                            Sign in
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6" /></svg>
                        </a>
                        <a href="#workspaces" class="lp-button lp-button--secondary">Join the network</a>
                    </div>
                    <p class="lp-hero__note"><span aria-hidden="true"></span>For the people behind every delivery.</p>
                </div>
                <div class="lp-hero__caption" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M4 6h11v11H4V6Zm11 4h4l3 4v3h-7M2 10h5M1 13h6" /><circle cx="7.5" cy="18" r="2" /><circle cx="18.5" cy="18" r="2" /></svg>
                    <div><strong>Every parcel has a journey.</strong><span>Let's move it forward, together.</span></div>
                </div>
            </div>
            <ul class="lp-highlights" aria-label="Portal capabilities">
                <li>
                    <span class="lp-highlight__icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 7 9-4 9 4v10l-9 4-9-4V7Zm0 0 9 4 9-4M12 11v10M7.5 5 17 9" /></svg></span>
                    <div><strong>Parcel operations</strong><span>Receive, sort, and dispatch.</span></div>
                </li>
                <li>
                    <span class="lp-highlight__icon"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="7" r="3" /><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 4a3 3 0 0 1 0 6M21 21v-3a6 6 0 0 0-4-5.7" /></svg></span>
                    <div><strong>Rider coordination</strong><span>Connect people with their next task.</span></div>
                </li>
                <li>
                    <span class="lp-highlight__icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s7-6 7-12a7 7 0 1 0-14 0c0 6 7 12 7 12Z" /><circle cx="12" cy="9" r="2.5" /></svg></span>
                    <div><strong>Delivery visibility</strong><span>Follow each parcel's progress.</span></div>
                </li>
            </ul>
        </section>

        <section id="workspaces" class="lp-workspaces" aria-labelledby="lp-workspaces-title">
            <header class="lp-section-head">
                <div>
                    <span class="lp-eyebrow">One network. Different roles.</span>
                    <h2 id="lp-workspaces-title">Your part in the journey.</h2>
                </div>
                <p>At the sorting center or out on the road,<br class="lp-desktop-break"> there's a workspace built around your day.</p>
            </header>
            <div class="lp-role-grid">
                <article class="lp-role">
                    <div class="lp-role__top">
                        <span class="lp-role__icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 9 9-6 9 6v12H3V9ZM8 21V11h8v10M8 15h8M8 18h8M1 9l11-7 11 7" /></svg></span>
                        <span class="lp-role__label">At the center</span>
                    </div>
                    <h3>Logistics centers</h3>
                    <p>Keep the day's operations in order, from incoming parcels to the next rider assignment.</p>
                    <ul class="lp-role__features">
                        <li>Receive and sort incoming parcels</li>
                        <li>Manage riders and delivery assignments</li>
                        <li>Monitor parcel progress and operations</li>
                    </ul>
                    <div class="lp-role__actions">
                        <a href="{{ route('partner.create') }}" class="lp-button lp-button--primary">
                            Apply as a center
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6" /></svg>
                        </a>
                        <a href="{{ route('logistics.login') }}" class="lp-text-link" aria-label="Sign in to your logistics center account">Sign in <span aria-hidden="true">↗</span></a>
                    </div>
                </article>
                <article class="lp-role lp-role--rider">
                    <div class="lp-role__top">
                        <span class="lp-role__icon"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5.5" cy="17.5" r="3.5" /><circle cx="18.5" cy="17.5" r="3.5" /><path d="m5.5 17.5 5-9 4.5 9H5.5Zm5-9h6l2 9M9 5h4M16.5 8.5 15 4h-3" /></svg></span>
                        <span class="lp-role__label">On the road</span>
                    </div>
                    <h3>Riders &amp; couriers</h3>
                    <p>Make every stop count with a clear view of your assigned pickups and deliveries.</p>
                    <ul class="lp-role__features">
                        <li>View your pickup and delivery assignments</li>
                        <li>Update parcel status along the way</li>
                        <li>Review your delivery history and earnings</li>
                    </ul>
                    <div class="lp-role__actions">
                        <a href="{{ route('logistics.login') }}" class="lp-button lp-button--primary">
                            Rider sign in
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6" /></svg>
                        </a>
                        <a href="{{ route('logistics.login') }}" class="lp-text-link" aria-label="Sign in to your rider account">Sign in <span aria-hidden="true">↗</span></a>
                    </div>
                </article>
            </div>
        </section>

        <section id="how-it-works" class="lp-workflow" aria-labelledby="lp-workflow-title">
            <header class="lp-workflow__head">
                <span class="lp-eyebrow">From pickup to doorstep</span>
                <h2 id="lp-workflow-title">Every handoff,<br><em>connected.</em></h2>
                <p>A shared journey for your team and every parcel in its care.</p>
            </header>
            <ol class="lp-steps">
                <li><span class="lp-step__number" aria-hidden="true">01</span><h3>Receive</h3><p>Bring parcels into the center and record their arrival.</p></li>
                <li><span class="lp-step__number" aria-hidden="true">02</span><h3>Sort</h3><p>Organize parcels for their next destination.</p></li>
                <li><span class="lp-step__number" aria-hidden="true">03</span><h3>Assign</h3><p>Connect each pickup or delivery with a rider.</p></li>
                <li><span class="lp-step__number" aria-hidden="true">04</span><h3>Deliver</h3><p>Update progress through to the final handoff.</p></li>
            </ol>
        </section>
    </main>

    <footer class="lp-footer lp-container">
        <p><strong>LIKHAE</strong><span>Moving local commerce forward.</span></p>
        <a href="{{ route('home') }}">Back to the marketplace <span aria-hidden="true">↗</span></a>
        <small>&copy; {{ date('Y') }} LIKHAE</small>
    </footer>
</body>
</html>
