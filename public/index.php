<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

use App\Repositories\ServiceRepository;

// Load active services for the cards section
$services = (new ServiceRepository())->allActive();

// If a visitor clicks a card while signed out, we send them to /login
// with a `next` parameter so they can be returned to their intended page.
$loginUrl = url('/login');
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(APP_NAME) ?> — Digital Services</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= e(asset('css/app.css')) ?>" rel="stylesheet">

    <style>
        /* ================= Wallpaper slideshow ================= */
        .hero-slideshow {
            position: absolute;
            inset: 0;
            overflow: hidden;
            z-index: 0;
        }
        .hero-slideshow .slide {
            position: absolute;
            inset: 0;
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            opacity: 0;
            transition: opacity 1.2s ease-in-out;
            will-change: opacity;
        }
        .hero-slideshow .slide.is-active { opacity: 1; }

        .hero-slideshow::after {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(
                135deg,
                rgba(15, 23, 42, 0.55) 0%,
                rgba(15, 23, 42, 0.35) 45%,
                rgba(15, 23, 42, 0.65) 100%
            );
            pointer-events: none;
            z-index: 1;
        }

        /* ================= Hero content layer ================= */
        .hero-wrap {
            position: relative;
            z-index: 2;
            min-height: 50vh;
            display: flex;
            flex-direction: column;
        }
        .hero-nav { padding: 20px 0; }
        .hero-nav .navbar-brand {
            color: #fff;
            font-weight: 700;
            font-size: 1.35rem;
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.55);
        }
        .hero-nav .btn-outline-light { border-width: 2px; }

        .hero-content {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 16px 60px;
        }

        .slide-copy {
            display: none;
            max-width: 880px;
            text-align: center;
            color: #fff;
            text-shadow: 0 3px 14px rgba(0, 0, 0, 0.75), 0 1px 3px rgba(0, 0, 0, 0.9);
            animation: fadeUp 0.9s ease both;
        }
        .slide-copy.is-active { display: block; }

        .slide-copy h1 {
            font-size: clamp(2rem, 4.6vw, 3.6rem);
            font-weight: 800;
            line-height: 1.15;
            margin-bottom: 1rem;
            letter-spacing: -0.5px;
        }
        .slide-copy p {
            font-size: clamp(1rem, 1.6vw, 1.2rem);
            font-weight: 500;
            opacity: 0.95;
            margin-bottom: 2rem;
        }

        .hero-dots {
            position: absolute;
            left: 0; right: 0; bottom: 26px;
            display: flex;
            justify-content: center;
            gap: 10px;
            z-index: 3;
        }
        .hero-dots button {
            width: 12px; height: 12px;
            border-radius: 50%;
            border: 2px solid rgba(255, 255, 255, 0.85);
            background: transparent;
            padding: 0;
            transition: background 0.25s, transform 0.25s;
        }
        .hero-dots button.is-active {
            background: #fff;
            transform: scale(1.15);
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(14px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ================= Services section ================= */
        .services-section {
            position: relative;
            z-index: 2;
            background: #f5f7fb;
            padding: 72px 0 80px;
        }
        .services-section h2 {
            font-weight: 800;
            color: #0f172a;
            font-size: clamp(1.5rem, 2.6vw, 2.1rem);
            margin-bottom: 0.5rem;
        }
        .services-section .lead {
            color: #64748b;
            margin-bottom: 2.5rem;
        }

        .service-card {
            display: flex;
            flex-direction: column;
            background: #fff;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid rgba(15, 23, 42, 0.06);
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.05);
            text-decoration: none;
            color: inherit;
            height: 100%;
            transition: transform .18s ease, box-shadow .18s ease;
        }
        .service-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 14px 32px rgba(15, 23, 42, 0.12);
            color: inherit;
        }

        .service-card__image {
            position: relative;
            aspect-ratio: 16 / 10;
            background: linear-gradient(135deg, #e0e7ff 0%, #f0f9ff 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .service-card__image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform .35s ease;
        }
        .service-card:hover .service-card__image img {
            transform: scale(1.05);
        }
        .service-card__image .placeholder-icon {
            font-size: 3rem;
            color: #64748b;
        }

        .service-card__body {
            padding: 20px;
            display: flex;
            flex-direction: column;
            flex-grow: 1;
        }
        .service-card__title {
            font-size: 1.05rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 6px;
        }
        .service-card__desc {
            color: #64748b;
            font-size: .88rem;
            line-height: 1.5;
            flex-grow: 1;
            margin-bottom: 16px;
        }
        .service-card__meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-top: 1px solid #e2e8f0;
            padding-top: 14px;
        }
        .service-card__price {
            font-weight: 700;
            color: #1e3a8a;
            font-size: 1rem;
        }
        .service-card__cta {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 600;
            font-size: .85rem;
            color: #2563eb;
        }
        .service-card:hover .service-card__cta {
            color: #1e3a8a;
        }

        .services-footer-note {
            margin-top: 3rem;
            text-align: center;
            color: #64748b;
            font-size: .9rem;
        }


                /* ================= Footer ================= */
        .site-footer {
            margin-top: 80px;
            padding: 56px 0 24px;
            background: linear-gradient(
                135deg,
                #0e7490 0%,      /* deep cyan */
                #0891b2 35%,     /* cyan-600 */
                #06b6d4 65%,     /* cyan-500 */
                #22d3ee 100%     /* cyan-400 */
            );
            color: #e6feff;
            position: relative;
            overflow: hidden;
        }

        /* Soft glow decorations — subtle depth, no images required */
        .site-footer::before,
        .site-footer::after {
            content: "";
            position: absolute;
            border-radius: 50%;
            pointer-events: none;
            filter: blur(80px);
            opacity: 0.55;
        }
        .site-footer::before {
            width: 420px;
            height: 420px;
            background: rgba(255, 255, 255, 0.35);
            top: -160px;
            right: -120px;
        }
        .site-footer::after {
            width: 380px;
            height: 380px;
            background: rgba(6, 182, 212, 0.55);
            bottom: -180px;
            left: -140px;
        }

        .site-footer > .container {
            position: relative;
            z-index: 1;
        }

        .footer-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 40px;
            padding-bottom: 36px;
        }
        @media (min-width: 768px) {
            .footer-grid {
                grid-template-columns: 1.4fr 1fr;
                align-items: start;
            }
        }

                /* ================= Footer ================= */
        .site-footer {
            margin-top: 80px;
            padding: 56px 0 24px;
            background: linear-gradient(
                135deg,
                #050b1c 0%,      /* nearly black navy */
                #0a1a4a 30%,     /* deep navy */
                #102a6b 60%,     /* mid navy */
                #1e3a8a 100%     /* brand navy (matches hero) */
            );
            color: #dbe4ff;
            position: relative;
            overflow: hidden;
        }

        /* Soft glow decorations — subtle depth, no images required */
        .site-footer::before,
        .site-footer::after {
            content: "";
            position: absolute;
            border-radius: 50%;
            pointer-events: none;
            filter: blur(90px);
            opacity: 0.5;
        }
        .site-footer::before {
            width: 440px;
            height: 440px;
            background: rgba(59, 130, 246, 0.35); /* blue-500 */
            top: -170px;
            right: -130px;
        }
        .site-footer::after {
            width: 400px;
            height: 400px;
            background: rgba(37, 99, 235, 0.28); /* blue-600 */
            bottom: -190px;
            left: -150px;
        }

        .site-footer > .container {
            position: relative;
            z-index: 1;
        }

        .footer-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 40px;
            padding-bottom: 36px;
        }
        @media (min-width: 768px) {
            .footer-grid {
                grid-template-columns: 1.4fr 1fr;
                align-items: start;
            }
        }

        /* ---------- Brand ---------- */
        .footer-brand-mark {
            display: inline-flex;
            align-items: center;
            gap: 14px;
            text-decoration: none;
            margin-bottom: 14px;
        }
        .footer-brand-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: #ffffff;
            background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
            box-shadow:
                0 8px 22px rgba(2, 12, 40, 0.55),
                inset 0 1px 0 rgba(255, 255, 255, 0.18);
        }
        .footer-brand-name {
            display: flex;
            flex-direction: column;
            line-height: 1.15;
        }
        .footer-brand-name-a {
            font-size: 1.4rem;
            font-weight: 800;
            letter-spacing: -0.4px;
            background: linear-gradient(90deg, #93c5fd 0%, #60a5fa 50%, #38bdf8 100%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            color: #93c5fd; /* fallback for browsers without background-clip:text */
        }
        .footer-brand-name-b {
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: 1.8px;
            text-transform: uppercase;
            color: rgba(219, 228, 255, 0.72);
            margin-top: 3px;
        }
        .footer-brand-tagline {
            color: rgba(219, 228, 255, 0.85);
            font-size: 0.92rem;
            line-height: 1.6;
            max-width: 400px;
            margin: 0;
        }

        /* ---------- Contact ---------- */
        .footer-heading {
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.6px;
            color: rgba(219, 228, 255, 0.75);
            margin-bottom: 18px;
        }
        .footer-contact-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 10px 0;
            text-decoration: none;
            color: inherit;
            border-bottom: 1px dashed rgba(147, 197, 253, 0.2);
            transition: transform 0.15s ease;
        }
        .footer-contact-item:last-child {
            border-bottom: none;
        }
        .footer-contact-item:hover {
            transform: translateX(3px);
            color: inherit;
        }
        .footer-contact-icon {
            width: 40px;
            height: 40px;
            flex: 0 0 40px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(96, 165, 250, 0.16);
            color: #93c5fd;
            font-size: 1.05rem;
            transition: background 0.15s ease, color 0.15s ease, transform 0.15s ease;
        }
        .footer-contact-item:hover .footer-contact-icon {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: #ffffff;
            transform: scale(1.05);
        }
        .footer-contact-text {
            display: flex;
            flex-direction: column;
            line-height: 1.3;
        }
        .footer-contact-label {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.9px;
            color: rgba(147, 197, 253, 0.7);
        }
        .footer-contact-value {
            font-size: 0.94rem;
            font-weight: 600;
            color: #ffffff;
            word-break: break-word;
        }

        /* ---------- Bottom bar ---------- */
        .footer-bottom {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: space-between;
            align-items: center;
            padding: 22px 0 0;
            border-top: 1px solid rgba(147, 197, 253, 0.18);
            font-size: 0.85rem;
            color: rgba(219, 228, 255, 0.72);
        }
        .footer-bottom-links {
            display: inline-flex;
            gap: 18px;
        }
        .footer-bottom-links a {
            color: rgba(219, 228, 255, 0.85);
            text-decoration: none;
            transition: color 0.15s ease;
        }
        .footer-bottom-links a:hover {
            color: #93c5fd;
            text-decoration: underline;
        }

    </style>
</head>
<body>

<!-- ================= Wallpaper slideshow ================= -->
<div class="hero-slideshow" id="heroSlideshow" aria-hidden="true">
    <div class="slide is-active" style="background-image:url('<?= e(asset('img/hero-2.jpg')) ?>');"></div>
    <div class="slide"           style="background-image:url('<?= e(asset('img/hero-2.jpg')) ?>');"></div>
</div>

<!-- ================= Hero ================= -->
<div class="hero-wrap">

    <nav class="hero-nav">
        <div class="container d-flex align-items-center">
            <a class="navbar-brand me-auto" href="<?= e(url('/')) ?>">
                <i class="bi bi-shield-check"></i> <?= e(APP_NAME) ?>
            </a>
            <a class="btn btn-outline-light btn-sm me-2" href="<?= e($loginUrl) ?>">Sign in</a>
            <a class="btn btn-light btn-sm" href="<?= e(url('/register')) ?>">Create account</a>
        </div>
    </nav>

    <main class="hero-content">
        <div class="slide-copy is-active" data-slide="0">
            <h1>Fast, secure digital services — all in one place.</h1>
            <p>Fund your wallet, submit a request, and track it live from your dashboard.</p>
            <div class="d-flex gap-2 justify-content-center flex-wrap">
                <a class="btn btn-lg btn-light px-4" href="<?= e(url('/register')) ?>">
                    Get started
                </a>
                <a class="btn btn-lg btn-outline-light px-4" href="<?= e($loginUrl) ?>">
                    Sign in
                </a>
            </div>
        </div>

             <div class="slide-copy" data-slide="1">
            <h1>Trusted processing, transparent tracking.</h1>
            <p>Every request gets a unique tracking ID. Watch progress update in real time.</p>
            <div class="d-flex gap-2 justify-content-center flex-wrap">
                <a class="btn btn-lg btn-light px-4" href="#services">
                    Browse services
                </a>
            </div>
        </div>
    </main>

    <div class="hero-dots" id="heroDots">
        <button type="button" class="is-active" data-goto="0" aria-label="Slide 1"></button>
        <button type="button" data-goto="1" aria-label="Slide 2"></button>
    </div>
</div>

<!-- ================= Services section ================= -->
<section class="services-section" id="services">
    <div class="container">

        <div class="text-center mb-4">
            <h2>Services we offer</h2>
            <p class="lead">
                Click any service to sign in and start your request.
            </p>
        </div>

        <?php if (empty($services)): ?>
            <div class="alert alert-info text-center">
                No services are available right now. Please check back soon.
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($services as $s):
                    $imageUrl = resolve_service_image($s);
                ?>
                    <div class="col-md-6 col-lg-4">
                        <a class="service-card"
                           href="<?= e($loginUrl . '?next=' . urlencode('/client/submit?service=' . urlencode((string) $s['slug']))) ?>">
                            <div class="service-card__image">
                                <?php if ($imageUrl): ?>
                                    <img src="<?= e($imageUrl) ?>" alt="<?= e($s['name']) ?>">
                                <?php else: ?>
                                    <i class="bi bi-briefcase placeholder-icon"></i>
                                <?php endif; ?>
                            </div>
                            <div class="service-card__body">
                                <div class="service-card__title"><?= e($s['name']) ?></div>
                                <p class="service-card__desc">
                                    <?= e($s['short_description'] ?: ($s['description'] ?: 'Tap to get started.')) ?>
                                </p>
                                <div class="service-card__meta">
                                    <span class="service-card__price"><?= e(money($s['price'])) ?></span>
                                    <span class="service-card__cta">
                                        Sign in <i class="bi bi-arrow-right"></i>
                                    </span>
                                </div>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>

            <p class="services-footer-note">
                <i class="bi bi-info-circle me-1"></i>
                You need an account to submit a request.
                <a href="<?= e(url('/register')) ?>">Create one free</a> in under a minute.
            </p>
        <?php endif; ?>

    </div>

</section>
    <!-- ================= Footer ================= -->
    <footer class="site-footer">
        <div class="container">
            <div class="footer-grid">

                <!-- Brand block -->
                <div class="footer-brand">
                    <a href="<?= e(url('/')) ?>" class="footer-brand-mark">
                        <span class="footer-brand-icon">
                            <i class="bi bi-shield-check"></i>
                        </span>
                        <span class="footer-brand-name">
                            <span class="footer-brand-name-a"><?= e(APP_NAME) ?></span>
                            <span class="footer-brand-name-b">Secure&nbsp;·&nbsp;Fast&nbsp;·&nbsp;Tracked</span>
                        </span>
                    </a>
                    <p class="footer-brand-tagline">
                        Reliable digital services with transparent tracking and secure processing.
                    </p>
                </div>

                <!-- Contact block -->
                <div class="footer-contact">
                    <h4 class="footer-heading">Contact us</h4>

                    <a class="footer-contact-item" href="mailto:hello@servicehub-ng.example">
                        <span class="footer-contact-icon"><i class="bi bi-envelope-fill"></i></span>
                        <span class="footer-contact-text">
                            <span class="footer-contact-label">Email</span>
                            <span class="footer-contact-value">PrestigiousICT@gmail.com</span>
                        </span>
                    </a>

                    <a class="footer-contact-item" href="https://wa.me/2348123456789" target="_blank" rel="noopener">
                        <span class="footer-contact-icon"><i class="bi bi-whatsapp"></i></span>
                        <span class="footer-contact-text">
                            <span class="footer-contact-label">WhatsApp</span>
                            <span class="footer-contact-value">+234 816 150 8927</span>
                        </span>
                    </a>

                
                </div>

            </div>

            <div class="footer-bottom">
                <span>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. All rights reserved.</span>
             
            </div>
        </div>
    </footer>



<script>
/* ================= Wallpaper slideshow ================= */
(function () {
    const SLIDE_INTERVAL_MS = 5000;

    const slides = document.querySelectorAll('#heroSlideshow .slide');
    const copies = document.querySelectorAll('.slide-copy');
    const dots   = document.querySelectorAll('#heroDots button');
    if (!slides.length) return;

    let current = 0;
    let timer   = null;

    function show(index) {
        index = (index + slides.length) % slides.length;
        slides.forEach((el, i) => el.classList.toggle('is-active', i === index));
        copies.forEach((el, i) => el.classList.toggle('is-active', i === index));
        dots.forEach((el, i)   => el.classList.toggle('is-active', i === index));
        current = index;
    }
    function start() { stop(); timer = setInterval(() => show(current + 1), SLIDE_INTERVAL_MS); }
    function stop()  { if (timer) { clearInterval(timer); timer = null; } }

    dots.forEach((btn, i) => btn.addEventListener('click', () => { show(i); start(); }));

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) stop(); else start();
    });

    show(0);
    start();
})();
</script>

</body>
</html>
