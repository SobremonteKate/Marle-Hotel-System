<?php
/**
 * dining_menu.php — the restaurant menu, printed on the public site.
 *
 * Sits directly under the mustard dining block so that "Dining" in the nav
 * leads somewhere a visitor can actually read: who cooks, and what. The dishes
 * and prices come from includes/menu_public.php (which reads the guest portal's
 * own catalogue), and every photograph carries the credit its licence requires.
 *
 * Requires: site_t(), and includes/menu_public.php (included here).
 * Provides: $publicMenuSections, $publicMenuSignature for anything that follows.
 */

require_once __DIR__ . '/menu_public.php';

if (empty($publicMenuSections)) {
    return;
}

$peso = static fn(int $amount): string => "\u{20B1}" . number_format($amount);
$esc  = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
?>

<div class="menu-block" id="diningMenu">
    <div class="shell">

        <header class="menu-block__head" data-reveal>
            <div>
                <p class="eyebrow"><?= site_t('The menu', 'Ang menu') ?></p>
                <h2 class="section-title" id="menuTitle"><?= site_t('What the kitchen is cooking', 'Ang niluluto ng kusina') ?></h2>
            </div>
            <p class="section-lead">
                <?= site_t(
                    'Filipino favourites, a few Western plates and a full bar — served in the restaurant, at the terrace, or in your room while you stay.',
                    'Mga paboritong pagkaing Pilipino, ilang Western na putahe at kumpletong bar — inihahain sa restaurant, sa terrace, o sa kwarto mo habang naka-stay.'
                ) ?>
            </p>
        </header>

        <?php if (!empty($publicMenuSignature)): ?>
        <section class="signature" aria-labelledby="signatureTitle" data-reveal>
            <div class="signature__head">
                <h3 class="signature__title" id="signatureTitle"><?= site_t('Signature plates', 'Mga espesyal na putahe') ?></h3>
                <p class="signature__note"><?= site_t('The dishes guests come back for.', 'Ang mga putaheng bumabalik-balikan.') ?></p>
            </div>

            <ul class="signature__grid">
                <?php foreach ($publicMenuSignature as $dish): $photo = $dish['photo']; ?>
                <li class="dishcard">
                    <figure class="dishcard__figure">
                        <picture>
                            <source srcset="<?= $esc($photo['webp']) ?>" type="image/webp">
                            <img src="<?= $esc($photo['jpg']) ?>" width="640" height="480"
                                 alt="<?= $esc(site_t($photo['alt'][0], $photo['alt'][1])) ?>"
                                 loading="lazy" decoding="async">
                        </picture>
                        <figcaption class="dishcard__credit">
                            <?= site_t('Photo', 'Larawan') ?>:
                            <a href="<?= $esc($photo['href']) ?>" target="_blank" rel="noopener noreferrer"><?= $esc($photo['by']) ?></a>
                            &middot; <?= $esc($photo['licence']) ?>
                        </figcaption>
                    </figure>

                    <div class="dishcard__body">
                        <h4 class="dishcard__name"><?= $esc($dish['name']) ?></h4>
                        <p class="dishcard__desc"><?= $esc(site_t($dish['desc'][0], $dish['desc'][1])) ?></p>
                        <p class="dishcard__line">
                            <span class="dishcard__serves"><?= $esc(site_t($dish['serves'][0], $dish['serves'][1])) ?></span>
                            <span class="dishcard__leader" aria-hidden="true"></span>
                            <span class="dishcard__price"><?= $peso((int)$dish['price']) ?></span>
                        </p>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>

        <div class="menu-block__body" data-reveal>
            <div class="menutabs" role="group" aria-label="<?= site_t('Menu sections', 'Mga seksyon ng menu') ?>">
                <button type="button" class="menutab is-active" data-menu-tab="all" aria-pressed="true">
                    <?= site_t('Everything', 'Lahat') ?>
                </button>
                <?php foreach ($publicMenuSections as $section): ?>
                    <button type="button" class="menutab" data-menu-tab="<?= $esc($section['slug']) ?>" aria-pressed="false">
                        <?= $esc(site_t($section['label'][0], $section['label'][1])) ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <p class="menutabs__legend">
                <span class="vegmark vegmark--inline" aria-hidden="true">V</span>
                <?= site_t('Vegetarian. Prices are in pesos and include service.', 'Vegetarian. Ang mga presyo ay sa piso at may kasamang serbisyo.') ?>
            </p>

            <div class="menusections" id="menuSections">
                <?php foreach ($publicMenuSections as $section): ?>
                <section class="menusec" data-menu-sec="<?= $esc($section['slug']) ?>"
                         aria-labelledby="menuSec-<?= $esc($section['slug']) ?>">
                    <header class="menusec__head">
                        <h3 class="menusec__title" id="menuSec-<?= $esc($section['slug']) ?>">
                            <?= $esc(site_t($section['label'][0], $section['label'][1])) ?>
                        </h3>
                        <p class="menusec__intro"><?= $esc(site_t($section['intro'][0], $section['intro'][1])) ?></p>
                    </header>

                    <ul class="menulist<?= $section['slug'] === 'drinks' ? ' menulist--two' : '' ?>">
                        <?php foreach ($section['items'] as $dish): ?>
                        <li class="mitem">
                            <div class="mitem__top">
                                <h4 class="mitem__name">
                                    <?= $esc($dish['name']) ?>
                                    <?php if ($dish['veg']): ?>
                                        <span class="vegmark" title="<?= site_t('Vegetarian', 'Vegetarian') ?>"
                                              aria-label="<?= site_t('Vegetarian', 'Vegetarian') ?>">V</span>
                                    <?php endif; ?>
                                </h4>
                                <span class="mitem__leader" aria-hidden="true"></span>
                                <span class="mitem__price"><?= $peso((int)$dish['price']) ?></span>
                            </div>
                            <p class="mitem__desc"><?= $esc(site_t($dish['desc'][0], $dish['desc'][1])) ?></p>
                            <p class="mitem__serves"><?= $esc(site_t($dish['serves'][0], $dish['serves'][1])) ?></p>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
                <?php endforeach; ?>
            </div>

            <p class="menublock__foot">
                <?= site_t('Something not on the list? Ask the kitchen.', 'May gusto kang wala sa listahan? Itanong sa kusina.') ?>
                <a class="text-link" href="contact.php"><?= site_t('Reserve a table', 'Magreserba ng mesa') ?>
                    <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            </p>
        </div>
    </div>
</div>
