<?php
require_once __DIR__ . '/../includes/site_i18n.php';
site_lang(); // resolve (and persist) the language before any output
?>
<!DOCTYPE html>
<html lang="<?= site_html_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= site_t('Contact Marle Hotel & Restaurant in Sabutan Ibayo, Silang, Cavite.', 'Makipag-ugnayan sa Marle Hotel & Restaurant sa Sabutan Ibayo, Silang, Cavite.') ?>">
    <meta name="theme-color" content="#7A2A15">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400..700;1,9..144,400..600&family=IBM+Plex+Mono:wght@400;500&family=Instrument+Sans:ital,wght@0,400..700;1,400&display=swap" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">    <link rel="stylesheet" href="site.css?v=20261029c">
<?php include __DIR__ . '/includes/site_icons.php'; ?>

    <title><?= site_t('Contact | Marle Hotel', 'Makipag-ugnayan | Marle Hotel') ?></title>
</head>
<body class="site">
<?php
$navActive = 'contact';
include __DIR__ . '/includes/site_header.php';
?>

<main id="main">
    <section class="page-hero shell" aria-labelledby="contactTitle">
        <div class="page-hero__copy">
            <p class="eyebrow"><?= site_t('Get in touch', 'Makipag-ugnayan') ?></p>
            <h1 id="contactTitle"><?= site_t('Come find us in Silang.', 'Hanapin kami sa Silang.') ?></h1>
            <p class="page-hero__lead"><?= site_t('A comfortable stay is only a message or a short drive away.', 'Isang mensahe o maikling biyahe lang ang layo ng komportableng pamamalagi.') ?></p>

            <div class="contact-cards">
                <a href="tel:09170000000">
                    <i class="bi bi-telephone" aria-hidden="true"></i>
                    <span><small><?= site_t('Front desk', 'Front desk') ?></small>0917 000 0000</span>
                </a>
                <a href="mailto:frontdesk@marlehotel.example">
                    <i class="bi bi-envelope" aria-hidden="true"></i>
                    <span><small>Email</small>frontdesk@marlehotel.example</span>
                </a>
                <a href="https://www.facebook.com/MARLE-Hotel-Restaurant-264513383682017/" target="_blank" rel="noopener">
                    <i class="bi bi-facebook" aria-hidden="true"></i>
                    <span><small>Facebook</small><?= site_t('Marle Hotel & Restaurant', 'Marle Hotel & Restaurant') ?></span>
                </a>
                <span class="contact-row">
                    <i class="bi bi-geo-alt" aria-hidden="true"></i>
                    <span><small><?= site_t('Address', 'Tirahan') ?></small>Sabutan Ibayo, Silang, Cavite</span>
                </span>
            </div>

            <a class="text-link" href="home.php#bookingArea"><?= site_t('Check room availability', 'Tingnan ang availability ng kwarto') ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        </div>

        <div class="findus__map">
            <iframe src="https://www.google.com/maps?q=Sabutan%20Ibayo%2C%20Silang%2C%20Cavite&amp;output=embed"
                    title="<?= site_t('Map showing Marle Hotel in Sabutan Ibayo, Silang, Cavite', 'Mapa na nagpapakita ng Marle Hotel sa Sabutan Ibayo, Silang, Cavite') ?>"
                    loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
        </div>
    </section>
</main>

<?php include __DIR__ . '/includes/site_footer.php'; ?>

<?php include __DIR__ . '/includes/site_help.php'; ?>

<script src="site-nav.js?v=20261029b"></script>
<script src="site-guide.js"></script>
</body>
</html>
