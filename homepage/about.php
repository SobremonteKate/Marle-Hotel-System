<?php
require_once __DIR__ . '/../includes/site_i18n.php';
site_lang(); // resolve (and persist) the language before any output
?>
<!DOCTYPE html>
<html lang="<?= site_html_lang() ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= site_t('Marle Hotel & Restaurant has served Sabutan Ibayo, Silang, Cavite since 2015.', 'Naglilingkod ang Marle Hotel & Restaurant sa Sabutan Ibayo, Silang, Cavite mula 2015.') ?>">
    <meta name="theme-color" content="#7A2A15">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400..700;1,9..144,400..600&family=IBM+Plex+Mono:wght@400;500&family=Instrument+Sans:ital,wght@0,400..700;1,400&display=swap" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="site.css?v=20261029c">
<?php include __DIR__ . '/includes/site_icons.php'; ?>

    <title><?= site_t('Our Story | Marle Hotel', 'Ang Kwento Namin | Marle Hotel') ?></title>
</head>

<body class="site">
<?php
$navActive = 'story';
include __DIR__ . '/includes/site_header.php';
?>

<main id="main">
    <section class="page-hero shell" aria-labelledby="storyTitle">
        <div class="page-hero__copy">
            <p class="eyebrow"><?= site_t('Our background', 'Ang aming pinagmulan') ?></p>
            <h1 id="storyTitle"><?= site_t('A stay shaped by service and connection.', 'Isang pamamalagi na hinubog ng serbisyo at ugnayan.') ?></h1>
            <p class="page-hero__lead"><?= site_t('Since opening in 2015, Marle Hotel has been proud to serve the community.', 'Mula nang magbukas noong 2015, ipinagmamalaki ng Marle Hotel ang paglilingkod sa komunidad.') ?></p>
            <p><?= site_t(
                'We are committed to not only providing quality services, but going above and beyond to ensure our customers are completely satisfied. As one of the leading hotels in the area, we attribute our reputation to the lasting customer relationships we have developed throughout the years.',
                'Nakatuon kami hindi lamang sa pagbibigay ng de-kalidad na serbisyo kundi sa paglampas pa rito upang matiyak na lubos na nasisiyahan ang aming mga bisita. Bilang isa sa mga nangungunang hotel sa lugar, iniuugnay namin ang aming reputasyon sa matibay na ugnayan sa mga bisitang napaglilingkuran namin sa loob ng mga taon.'
            ) ?></p>
            <p><?= site_t(
                'We believe that all of our customers deserve the highest level of service, and we are committed to providing just that.',
                'Naniniwala kami na karapat-dapat ang bawat bisita sa pinakamataas na antas ng serbisyo, at nakatuon kami sa pagbibigay nito.'
            ) ?></p>

            <dl class="facts">
                <div>
                    <dt><?= site_t('Since', 'Mula') ?></dt>
                    <dd>2015</dd>
                </div>
                <div>
                    <dt><?= site_t('Where', 'Saan') ?></dt>
                    <dd>Silang, Cavite</dd>
                </div>
                <div>
                    <dt><?= site_t('Open', 'Bukas') ?></dt>
                    <dd><?= site_t('Rooms & kitchen', 'Kwarto at kusina') ?></dd>
                </div>
            </dl>

            <a class="text-link" href="home.php#rooms"><?= site_t('See rooms and rates', 'Tingnan ang mga kwarto at presyo') ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        </div>

        <figure class="page-hero__media">
            <img src="pics/MarleHotelFront.jpg" width="2048" height="1383"
                 alt="<?= site_t('Marle Hotel front entrance', 'Harapang pasukan ng Marle Hotel') ?>"
                 decoding="async">
        </figure>
    </section>
</main>

<?php include __DIR__ . '/includes/site_footer.php'; ?>

<?php include __DIR__ . '/includes/site_help.php'; ?>

<script src="site-nav.js?v=20261029b"></script>
<script src="site-guide.js"></script>
</body>

</html>
