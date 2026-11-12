<?php
include('../dbconnect.php');

// A database that is down must reach this screen as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'page', 'staff');

// English | Tagalog for this whole screen (includes/ui_i18n.php). Must run
// before anything is echoed: the switch rewrites the finished page.
require_once __DIR__ . '/../includes/ui_i18n.php';
ui_i18n_start('staff.reviews');
include('auth_admin.php');
include('../admin/requests_badge.php');
include('../admin/includes/notif_data.php');
$sql = "SELECT * FROM customerstbl";
$result = mysqli_query($conn, $sql);

// Fetch all reviews from DB
$reviewsSql    = "SELECT * FROM reviewstbl ORDER BY review_date DESC";
$reviewsResult = mysqli_query($conn, $reviewsSql);
$allReviews    = [];
if ($reviewsResult) {
    while ($row = mysqli_fetch_assoc($reviewsResult)) {
        $allReviews[] = $row;
    }
}

// Logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    header("Location: ../login.php?logout=success");
    exit();
}
?>

<!DOCTYPE html>
<html lang="<?= ui_html_lang() ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-4Q6Gf2aSP4eDXB8Miphtr37CMZZQ5oXLH2yaXMJ2w8e2ZtHTl7GptT4jmndRuHDT" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.lineicons.com/5.0/lineicons.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">

    <!-- icon, to be changed -->
    <?php require __DIR__ . '/../includes/staff_icons.php'; ?>
    <link rel="stylesheet" href="../css/sidebar-theme.css?v=20260930">
    <link rel="stylesheet" href="../css/staff-footer.css?v=20261021">

    <!-- For charts/graphs -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.0"></script>

    <title>Reviews</title>

    <style>
        body {
            overflow-x: hidden;
            background-color: #f2ece9;
        }

        /* Desktop Sidebar Layout */
        @media (min-width: 768px) {
            .navbar {
                width: 230px;
                height: 100vh;
                position: fixed;
                top: 0;
                left: 0;
                background-color: #f8f9fa;
                display: flex;
                flex-direction: column;
            }

            .navbar>.container-fluid {
                height: 100%;
                display: flex;
                flex-direction: column;
            }

            .navbar-collapse {
                display: flex !important;
                flex-direction: column;
                flex: 1;
            }

            .content {
                margin-left: 230px;
                margin-top: 1.5rem;
            }
        }

        .nav-link {
            padding: 12px 18px;
            color: #555;
            border-radius: 12px;
            position: relative;
            transition: all 0.3s ease;
        }

        .nav-link:hover { background-color: #ffb60a; color: #fff; }

        .nav-link.active {
            background-color: #ffb60a;
            color: #fff !important;
            font-weight: 500;
        }

        .nav-link.active::before {
            content: "";
            position: absolute;
            left: -12px;
            top: 8px;
            bottom: 8px;
            width: 4px;
            border-radius: 4px;
            background-color: #ffb60a;
        }

        .nav-link i { font-size: 18px; }

        .notification-badge {
            background-color: #ffb60a;
            color: #fff;
            font-size: 12px;
            padding: 5px 8px;
        }

        .logout-btn {
            background-color: #933310;
            color: #fff;
            border: none;
            padding: 10px;
            border-radius: 12px;
            transition: 0.3s;
        }

        a { color: #ffb60a; text-decoration: none; }

        .logout-btn:hover,
        #yesBtn:hover,
        #noBtn:hover {
            background-color: transparent;
            border-color: #933310;
            color: #933310;
        }

        #yesBtn { background-color: #ffb60a; color: #fff; }

        /* Cards */
        .card { border-radius: 20px; padding-left: 15px; }

        .card .bi-chevron-double-up {
            border: 1px #008000 solid;
            border-radius: 5px;
            color: #008000;
            padding: 2px;
        }

        .card .bi-chevron-double-down {
            border: 1px red solid;
            border-radius: 5px;
            color: red;
            padding: 2px;
        }

        .card h5 { font-weight: 400; margin-top: 15px; }
        .card-text { font-size: 30px; font-weight: 700; }

        /* Search */
        #myInput { border: solid #ffb60a 3px; height: 45px; }

        .bi-bell {
            font-size: 20px;
            padding: 7px;
            border-radius: 10px;
            background-color: #fff;
            color: #000;
            margin-right: 0;
        }

        /* Ratings Card */
        .ratings-card { border-radius: 20px; padding: 20px; }

        .rating-box {
            margin-top: 30px;
            background-color: #f3f1ef;
            border-radius: 16px;
            padding: 30px 20px;
        }

        .rating-number { font-size: 70px; font-weight: 700; }

        .stars i { color: #ffb60a; font-size: 18px; margin: 0 2px; }

        /* Reviews section */
        .review-card {
            max-width: 1220px;
            margin: auto;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
        }

        @media (max-width: 768px) {
            .review-card { margin: 10px; }
        }

        .review-card .card-header {
            background: transparent;
            border-bottom: none;
            padding: 25px 25px 0 25px;
        }

        .review-card .card-body { padding: 20px 25px 25px 25px; }

        .search-box { border: solid #ffb60a 3px; max-width: 220px; }

        /* Review item */
        .review-item {
            display: flex;
            gap: 20px;
            padding: 20px 0;
            border-bottom: 1px solid #eee;
        }

        .review-img {
            width: 70px;
            height: 70px;
            border-radius: 12px;
            object-fit: cover;
        }

        .review-title { font-weight: 600; font-size: 20px; }

        .review-meta {
            font-size: 14px;
            color: #777;
            margin-left: 15px;
            max-width: 900px;
        }

        .review-stars i { color: #ffb60a; font-size: 14px; }

        .review-text { color: #000; font-size: 16px; margin-top: 5px; }

        .rating-num { font-size: 17px; font-weight: 600; }
        .rating-score { font-size: 14px; margin-left: 4px; }
        .review-date { font-size: 13px; color: #777; }

        /* Pagination */
        .page-link { color: #000; }
        .page-link:hover { background-color: #ffb60a; color: #fff; }
        .page-item { margin: 5px; }
        .review-card .pagination { flex-wrap: wrap; gap: 2px; }
        .review-filter-hidden { display: none !important; }

        .page {
            border: solid 1px #c4c2c2;
            border-radius: 10px;
            padding: 7px 10px;
            background: transparent;
        }

        .sentimentAnalysisBtn {
            color: #fff;
            background-color: #ffb60a;
            padding: 10px;
            border-radius: 12px;
            margin-right: 35px;
            height: 60px;
            width: 15%;
            font-size: 17px;
            font-weight: 500;
        }

        .sentimentAnalysisBtn:hover {
            border-color: #933310;
            color: #933310;
            background-color: transparent;
        }

        @media (max-width: 768px) { .review-card { margin-right: 10px; } }

        .bg-custom-success { background-color: #ffb60a !important; }

        /* Footer */
        .footer { margin-top: 3rem; text-decoration: none; color: #933310; }

        /* ── Sentiment Modal Styles ────────────────────────────────────── */
        #sentimentModal .modal-dialog { max-width: 780px; }

        .sentiment-score-ring {
            width: 110px;
            height: 110px;
            border-radius: 50%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            font-weight: 700;
            border: 6px solid;
            margin: 0 auto 8px;
        }

        .sentiment-ring-positive { border-color: #4caf50; color: #4caf50; }
        .sentiment-ring-neutral  { border-color: #ffb60a; color: #ffb60a; }
        .sentiment-ring-negative { border-color: #e53935; color: #e53935; }
        .sentiment-ring-mixed    { border-color: #7e57c2; color: #7e57c2; }

        .sentiment-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-positive { background-color: #e8f5e9; color: #2e7d32; }
        .badge-neutral  { background-color: #fff8e1; color: #f57f17; }
        .badge-negative { background-color: #ffebee; color: #c62828; }
        .badge-mixed    { background-color: #ede7f6; color: #4527a0; }
        .badge-na       { background-color: #f5f5f5; color: #757575; }

        .keyword-tag {
            display: inline-block;
            background: #f3f1ef;
            color: #555;
            border-radius: 20px;
            padding: 3px 10px;
            font-size: 12px;
            margin: 2px;
        }

        .cat-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .cat-row:last-child { border-bottom: none; }

        .review-row-sa {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 8px 0;
            border-bottom: 1px solid #f5f5f5;
            font-size: 13px;
        }

        .review-row-sa:last-child { border-bottom: none; }

        .sentiment-loading {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 50px 20px;
        }

        .sentiment-loading .spinner-border { width: 3rem; height: 3rem; color: #ffb60a; }

        #sentimentResults { display: none; }
        #sentimentError   { display: none; }
    </style>
    <link rel="stylesheet" href="../css/staff-reviews.css?v=20261021">
    <link rel="stylesheet" href="../css/staff-logout.css">
<?php require_once __DIR__ . '/../includes/server_clock.php'; marle_server_clock_assets(); ?>
<?php require __DIR__ . '/../includes/staff_theme.php'; ?>
</head>

<body class="staff-page staff-reviews-page staff-admin">
    <?php require __DIR__ . '/../includes/staff_sidebar.php'; ?>

    <!-- Welcome message + search -->
    <?php
    $staffPageTitle  = 'Reviews';
    $staffPageNote   = 'Guest feedback and sentiment, newest first.';
    $staffPageCrumbs = [['Dashboard', 'adminDB.php'], ['Reviews', null]];
    require __DIR__ . '/../includes/staff_page_head.php';
    ?>

    <?php
    // What the classified reviews add up to — Marle AI's stored output, grouped
    // by theme and ranked, so the analysis ends in a decision rather than a
    // modal. Server-rendered, no JavaScript, shared by all three role copies
    // (includes/sentiment_insights.php).
    require_once __DIR__ . '/../includes/sentiment_insights.php';
    echo marle_render_sentiment_insights(marle_sentiment_insights($conn));
    ?>

    <!-- Card + Bar graph -->
    <div class="content p-4">
        <div class="container">
            <div class="row g-3">

                <div class="col-12 col-md-3">
                    <div class="card ratings-card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="mb-0">Ratings</h5>
                                <small class="text-muted">This Month</small>
                            </div>
                            <div class="rating-box text-center">
                                <h1 class="rating-number mb-2" id="avgRatingNumber">—</h1>
                                <div class="stars mb-2">
                                    <i class="bi bi-star dynamic-star"></i>
                                    <i class="bi bi-star dynamic-star"></i>
                                    <i class="bi bi-star dynamic-star"></i>
                                    <i class="bi bi-star dynamic-star"></i>
                                    <i class="bi bi-star dynamic-star"></i>
                                </div>
                                <p class="mb-0 text-muted small" id="avgRatingCount">0 Reviews</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bar Chart -->
                <div class="col-12 col-md-9">
                    <div class="card shadow-sm rounded-4 p-4">
                        <canvas id="barChart" style="height: 300px;"></canvas>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- Reviews section -->
    <div class="content">
        <div class="card review-card mt-4">
            <div class="card-header d-flex justify-content-between align-items-center">

                <div class="d-flex gap-3">
                    <!-- Rating filter -->
                    <div class="dropdown">
                        <button class="btn dropdown-toggle" id="ratingFilterBtn" data-bs-toggle="dropdown" style="height:45px;">
                            <i class="bi bi-star-fill" style="color: #ffb60a;"></i> All Rating
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item rating-filter" href="#" data-rating="5">⭐⭐⭐⭐⭐ 5 Stars</a></li>
                            <li><a class="dropdown-item rating-filter" href="#" data-rating="4">⭐⭐⭐⭐ 4 Stars</a></li>
                            <li><a class="dropdown-item rating-filter" href="#" data-rating="3">⭐⭐⭐ 3 Stars</a></li>
                            <li><a class="dropdown-item rating-filter" href="#" data-rating="2">⭐⭐ 2 Stars</a></li>
                            <li><a class="dropdown-item rating-filter" href="#" data-rating="1">⭐ 1 Star</a></li>
                            <hr class="dropdown-divider">
                            <li><a class="dropdown-item text-danger rating-filter" href="#" data-rating="0">Clear Filter</a></li>
                        </ul>
                    </div>

                    <!-- Category filter -->
                    <div class="dropdown">
                        <button class="btn dropdown-toggle" id="categoryFilterBtn" data-bs-toggle="dropdown" style="height:45px;">
                            All Category
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item category-filter" href="#" data-category="hotel">Hotel</a></li>
                            <li><a class="dropdown-item category-filter" href="#" data-category="restaurant">Restaurant</a></li>
                            <li><a class="dropdown-item category-filter" href="#" data-category="amenities">Amenities</a></li>
                            <li><a class="dropdown-item category-filter" href="#" data-category="service">Service</a></li>
                            <hr class="dropdown-divider">
                            <li><a class="dropdown-item text-danger category-filter" href="#" data-category="">Clear Filter</a></li>
                        </ul>
                    </div>
                </div>

                <!-- Date filter -->
                <div class="dropdown">
                    <button class="btn dropdown-toggle" id="dateFilterBtn" data-bs-toggle="dropdown" style="height:45px;">
                        All Time
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item date-filter" href="#" data-period="today">Today</a></li>
                        <li><a class="dropdown-item date-filter" href="#" data-period="week">This Week</a></li>
                        <li><a class="dropdown-item date-filter" href="#" data-period="month">This Month</a></li>
                        <li><a class="dropdown-item date-filter" href="#" data-period="year">This Year</a></li>
                        <hr class="dropdown-divider">
                        <li><a class="dropdown-item text-danger date-filter" href="#" data-period="">Clear Filter</a></li>
                    </ul>
                </div>

            </div>

            <!-- Actual reviews content — rendered from DB -->
            <div class="card-body reviews-wrapper">

                <?php if (empty($allReviews)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-chat-square-text fs-1 d-block mb-2"></i>
                        No reviews found in the database yet.
                    </div>
                <?php else: ?>
                    <?php foreach ($allReviews as $review):
                        $rating   = (int)$review['rating'];
                        $category = htmlspecialchars($review['category']);
                        $dateRaw  = $review['review_date'];
                        $dateFmt  = date('M j, Y', strtotime($dateRaw));
                        $isAnonymous = trim((string)$review['guest_name']) === 'Anonymous Guest';
                        $name     = $isAnonymous ? '' : htmlspecialchars($review['guest_name']);
                        $room     = htmlspecialchars($review['room_no']);
                        $text     = htmlspecialchars($review['review_text']);
                    ?>
                    <div class="review-item"
                         data-rating="<?php echo $rating; ?>"
                         data-category="<?php echo $category; ?>"
                         data-date="<?php echo $dateRaw; ?>"
                         data-text="<?php echo $text; ?>"
                         data-name="<?php echo $name; ?>"
                         data-room="<?php echo $room; ?>">

                        <img src="../img/default.jpg" class="review-img">

                        <div class="flex-grow-1">
                            <div class="d-flex justify-space-between column-gap-5">

                                <div>
                                    <?php if (!$isAnonymous): ?><div class="review-title"><?php echo $name; ?></div><?php endif; ?>
                                    <small class="text-muted">Room <?php echo $room; ?></small>
                                </div>

                                <div class="review-meta">
                                    <div class="review-stars">
                                        <?php for ($s = 1; $s <= 5; $s++): ?>
                                            <i class="bi <?php echo $s <= $rating ? 'bi-star-fill' : 'bi-star'; ?>"></i>
                                        <?php endfor; ?>
                                        <span class="rating-score">
                                            <span class="rating-num"><?php echo $rating; ?></span>/5
                                        </span>
                                    </div>

                                    <div class="review-date"><?php echo $dateFmt; ?></div>
                                    <div class="small fw-semibold text-muted mt-1">
                                        Review type: <?php echo ucfirst($category); ?>
                                    </div>

                                    <p class="review-text"><?php echo $text; ?></p>
                                </div>

                            </div>
                        </div>

                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- Pagination + count -->
                <div class="d-flex justify-content-between align-items-center mt-4">
                    <div class="text-muted d-flex align-items-center gap-2">
                        <span>Showing <span id="visibleCount"><?php echo count($allReviews); ?></span>
                        of <span id="totalCount"><?php echo count($allReviews); ?></span> reviews</span>
                    </div>

                    <nav>
                        <ul class="pagination mb-0">
                            <li class="page-item disabled">
                                <a class="page-link"><i class="bi bi-chevron-left"></i></a>
                            </li>
                            <li class="page-item"><a class="page-link" href="#">1</a></li>
                            <li class="page-item">
                                <a class="page-link" href="#"><i class="bi bi-chevron-right"></i></a>
                            </li>
                        </ul>
                    </nav>
                </div>

            </div>
        </div>
    </div>

    <!-- Sentiment Analysis btn — opens modal -->
    <div class="sentiment-analysis-wrap d-flex justify-content-end mt-4">
        <button type="button"
                class="btn sentimentAnalysisBtn"
                data-bs-toggle="modal"
                data-bs-target="#sentimentModal"
                id="openSentimentBtn">
            <i class="bi bi-bar-chart-line me-1"></i> Sentiment Analysis
        </button>
    </div>

    <!-- Footer — one design for all three roles (includes/staff_footer.php) -->
    <?php $marleFooterTerms = true; require __DIR__ . '/../includes/staff_footer.php'; ?>

    <!-- ═══════════════════════════════════════════════════════════════════════
         SENTIMENT ANALYSIS MODAL
    ═══════════════════════════════════════════════════════════════════════ -->
    <div class="modal fade" id="sentimentModal" tabindex="-1" aria-labelledby="sentimentModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">

                <div class="modal-header" style="background: linear-gradient(135deg,#ffb60a,#e6960a); color:#fff;">
                    <div>
                        <h4 class="modal-title mb-0" id="sentimentModalLabel">
                            <i class="bi bi-bar-chart-line me-2"></i>Sentiment Analysis
                        </h4>
                        <small id="sentimentSubtitle" style="opacity:.85;">Analyzing visible reviews…</small>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body p-4">

                    <div class="row g-3 mb-4" id="lunaControls">
                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold" for="lunaLimit">Latest reviews</label>
                            <input type="number" class="form-control" id="lunaLimit" min="1" max="100" value="100">
                            <small class="text-muted">Maximum 100 per analysis</small>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold" for="lunaCategory">Category</label>
                            <select class="form-select" id="lunaCategory">
                                <option value="">All categories</option>
                                <option value="hotel">Hotel</option>
                                <option value="restaurant">Restaurant</option>
                                <option value="amenities">Amenities</option>
                                <option value="service">Service</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold" for="lunaPeriod">Date range</label>
                            <select class="form-select" id="lunaPeriod">
                                <option value="">All dates</option>
                                <option value="today">Today</option>
                                <option value="week">This week</option>
                                <option value="month">This month</option>
                                <option value="year">This year</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold" for="lunaLanguage">Language filter</label>
                            <select class="form-select" id="lunaLanguage"><option value="">All languages</option><option value="english">English</option><option value="tagalog">Tagalog</option></select>
                        </div>
                    </div>

                    <!-- Loading State -->
                    <div id="sentimentLoading" class="sentiment-loading">
                        <div class="spinner-border mb-3" role="status"></div>
                        <p class="text-muted mb-0">Groq is analyzing your reviews…</p>
                        <small class="text-muted">This may take a few seconds</small>
                    </div>

                    <!-- Error State -->
                    <div id="sentimentError" class="alert alert-danger align-items-center gap-2" style="display:none;">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <span id="sentimentErrorMsg">Something went wrong.</span>
                    </div>

                    <!-- Results -->
                    <div id="sentimentResults">

                        <!-- Row 1: Score ring + Summary -->
                        <div class="row g-4 mb-4">
                            <div class="col-12 col-md-3 text-center">
                                <div class="sentiment-score-ring" id="scoreRing">—</div>
                                <div id="overallLabel" class="fw-bold mt-1"></div>
                                <small class="text-muted">Sentiment Score</small>
                            </div>
                            <div class="col-12 col-md-9 d-flex align-items-center">
                                <div>
                                    <h6 class="fw-bold mb-2"><i class="bi bi-chat-quote me-1"></i>Summary</h6>
                                    <p id="summaryText" class="text-muted mb-2" style="font-size:15px;"></p>
                                    <div class="alert alert-warning py-2 px-3 mb-2" style="font-size:13px;">
                                        <i class="bi bi-lightbulb me-1"></i>
                                        <strong>Recommendation:</strong>
                                        <span id="recommendationText"></span>
                                    </div>
                                    <div id="actionPlanBlock" style="font-size:13px;">
                                        <h6 class="fw-bold mb-2"><i class="bi bi-list-check me-1"></i>Action Plan</h6>
                                        <ul id="actionPlanList" class="list-unstyled mb-0"></ul>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <hr>

                        <!-- Row 2: Strengths + Concerns -->
                        <div class="row g-4 mb-4">
                            <div class="col-12 col-md-6">
                                <h6 class="fw-bold mb-3 text-success">
                                    <i class="bi bi-hand-thumbs-up me-1"></i>Strengths
                                </h6>
                                <ul id="strengthsList" class="list-unstyled mb-0"></ul>
                            </div>
                            <div class="col-12 col-md-6">
                                <h6 class="fw-bold mb-3 text-danger">
                                    <i class="bi bi-hand-thumbs-down me-1"></i>Concerns
                                </h6>
                                <ul id="concernsList" class="list-unstyled mb-0"></ul>
                            </div>
                        </div>

                        <hr>

                        <!-- Row 3: Category breakdown -->
                        <div class="mb-4">
                            <h6 class="fw-bold mb-3">
                                <i class="bi bi-grid-3x3-gap me-1"></i>Category Breakdown
                            </h6>
                            <div id="categoryBreakdown"></div>
                        </div>

                        <hr>

                        <!-- Row 4: Per-review sentiment -->
                        <div>
                            <h6 class="fw-bold mb-3">
                                <i class="bi bi-person-lines-fill me-1"></i>Per-Review Sentiment
                            </h6>
                            <div id="perReviewList"></div>
                        </div>

                    </div><!-- /sentimentResults -->

                </div><!-- /modal-body -->

                <div class="modal-footer">
                    <small class="text-muted me-auto">
                        <i class="bi bi-magic me-1"></i>Powered by Groq (Qwen 3) · <span id="reviewCountLabel"></span>
                    </small>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn" id="rerunBtn"
                        style="background:#ffb60a;color:#fff;">
                        <i class="bi bi-bar-chart-line me-1"></i>Analyze Selected Reviews
                    </button>
                </div>

            </div>
        </div>
    </div>

    <!-- Terms & Conditions Modal -->
    <div class="modal fade" id="termsModal" tabindex="-1" aria-labelledby="termsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title" id="termsModalLabel">Terms & Conditions</h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="max-height: 75vh; overflow-y: auto;">
                    <h5>1. Acceptance of Terms</h5>
                    <p>By accessing and using this system, you acknowledge that you have read, understood, and agreed to comply with all applicable policies, rules, and regulations governing system usage.</p>
                    <h5>2. User Responsibilities</h5>
                    <p>Users are responsible for maintaining the confidentiality of their account credentials and for all activities conducted under their account.</p>
                    <ul>
                        <li>Do not share login credentials.</li>
                        <li>Log out after each session.</li>
                        <li>Report suspicious activity immediately.</li>
                    </ul>
                    <h5>3. Data Privacy and Protection</h5>
                    <p>All personal data collected will be handled in accordance with data protection laws and company privacy policies.</p>
                    <h5>4. System Security</h5>
                    <p>Unauthorized access, misuse, or tampering with system data is strictly prohibited and may result in disciplinary action.</p>
                    <h5>5. Limitation of Liability</h5>
                    <p>The company shall not be liable for any damages resulting from improper system use or unauthorized access.</p>
                    <h5>6. Amendments</h5>
                    <p>These terms may be updated at any time without prior notice. Continued use of the system constitutes acceptance of changes.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- LOGOUT MODAL -->
    <div class="modal fade" id="logoutConfirmModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Logout</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <p class="fs-5">Are you sure you want to log out?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" id="noBtn" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <a href="../logout.php" class="btn" id="yesBtn">Yes</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Search JS -->
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            const searchInput = document.getElementById("myInput");
            const rows = document.querySelectorAll(".review-item");
            if (!searchInput) return;

            searchInput.addEventListener("keyup", function () {
                reviewState.search = this.value.toLowerCase().trim();
                reviewState.page = 1;
                applyFilters();
            });
        });
    </script>

    <!-- Graph JS: Ratings Distribution & Filters -->
    <script>
        let ratingsChart = null;
        const reviewState = { search: '', page: 1 };
        const reviewsPerPage = 5;

        function getRatingsData(items) {
            const counts = { 1: 0, 2: 0, 3: 0, 4: 0, 5: 0 };
            items.forEach(item => {
                const r = parseInt(item.dataset.rating);
                if (counts[r] !== undefined) counts[r]++;
            });
            return [counts[1], counts[2], counts[3], counts[4], counts[5]];
        }

        function updateRatingsCard(items) {
            if (items.length === 0) {
                document.getElementById('avgRatingNumber').textContent = '—';
                document.getElementById('avgRatingCount').textContent  = '0 Reviews';
                updateStars(0);
                return;
            }
            const total = items.reduce((s, i) => s + parseInt(i.dataset.rating), 0);
            const avg   = (total / items.length).toFixed(1);
            document.getElementById('avgRatingNumber').textContent = avg;
            document.getElementById('avgRatingCount').textContent  = items.length + ' Reviews';
            updateStars(parseFloat(avg));
        }

        function updateStars(avg) {
            document.querySelectorAll('.dynamic-star').forEach((s, i) => {
                s.className = 'bi dynamic-star';
                if (avg >= i + 1)   s.classList.add('bi-star-fill');
                else if (avg >= i + 0.5) s.classList.add('bi-star-half');
                else                s.classList.add('bi-star');
            });
        }

        function updateChart(items) {
            if (ratingsChart) {
                ratingsChart.data.datasets[0].data = getRatingsData(items);
                ratingsChart.update();
            }
        }

        function getVisibleReviews() {
            return Array.from(document.querySelectorAll('.review-item')).filter(i => !i.classList.contains('review-filter-hidden'));
        }

        function renderPagination(items) {
            const pagination = document.querySelector('.review-card .pagination');
            if (!pagination) return;

            const pageCount = Math.max(1, Math.ceil(items.length / reviewsPerPage));
            reviewState.page = Math.min(reviewState.page, pageCount);
            const start = (reviewState.page - 1) * reviewsPerPage;
            const end = Math.min(start + reviewsPerPage, items.length);
            const visibleCount = items.length === 0 ? 0 : end - start;
            document.getElementById('visibleCount').textContent = visibleCount;
            document.getElementById('totalCount').textContent = items.length;

            pagination.innerHTML = '';
            const addPageButton = (label, page, disabled, active = false) => {
                const li = document.createElement('li');
                li.className = `page-item${disabled ? ' disabled' : ''}${active ? ' active' : ''}`;
                const link = document.createElement('a');
                link.className = 'page-link';
                link.href = '#';
                link.innerHTML = label;
                if (disabled) link.setAttribute('aria-disabled', 'true');
                if (!disabled) link.addEventListener('click', event => {
                    event.preventDefault();
                    reviewState.page = page;
                    applyFilters();
                });
                li.appendChild(link);
                pagination.appendChild(li);
            };

            const addEllipsis = () => {
                const li = document.createElement('li');
                li.className = 'page-item disabled';
                const span = document.createElement('span');
                span.className = 'page-link';
                span.textContent = '…';
                li.appendChild(span);
                pagination.appendChild(li);
            };

            addPageButton('<i class="bi bi-chevron-left"></i>', reviewState.page - 1, reviewState.page === 1);
            if (pageCount <= 7) {
                for (let page = 1; page <= pageCount; page++) {
                    addPageButton(String(page), page, false, page === reviewState.page);
                }
            } else {
                const pages = new Set([1, pageCount, reviewState.page - 1, reviewState.page, reviewState.page + 1]);
                const orderedPages = Array.from(pages).filter(page => page >= 1 && page <= pageCount).sort((a, b) => a - b);
                let previousPage = 0;
                orderedPages.forEach(page => {
                    if (page - previousPage > 1) addEllipsis();
                    addPageButton(String(page), page, false, page === reviewState.page);
                    previousPage = page;
                });
            }
            addPageButton('<i class="bi bi-chevron-right"></i>', reviewState.page + 1, reviewState.page === pageCount);
        }

        function applyFilters() {
            const activeRating   = document.getElementById('ratingFilterBtn').dataset.active   || '';
            const activeCategory = document.getElementById('categoryFilterBtn').dataset.active || '';
            const activePeriod   = document.getElementById('dateFilterBtn').dataset.active     || '';
            const search = reviewState.search;
            // "Today / this week / this month" are judged on the HOTEL's calendar — the
            // server clock and the database's own date (includes/server_clock.php) — not
            // on this device's. A phone whose clock was out, or a guest in another
            // timezone, used to change which reviews a filter showed.
            const hotelToday   = (window.MarleClock ? MarleClock.dayIso(0) : window.MARLE_HOTEL_TODAY) || '';
            const hotelWeekAgo = (window.MarleClock ? MarleClock.dayIso(-7) : window.MARLE_HOTEL_WEEK_AGO) || '';
            const allItems = Array.from(document.querySelectorAll('.review-item'));

            allItems.forEach(item => {
                let show = true;

                if (activeRating && parseInt(item.dataset.rating) !== parseInt(activeRating)) show = false;
                if (activeCategory && item.dataset.category !== activeCategory) show = false;
                if (search && !item.textContent.toLowerCase().includes(search)) show = false;

                if (activePeriod && show) {
                    // The review's date and "today" are both the hotel's own date strings
                    // (YYYY-MM-DD compares correctly as text), so no Date parsing and no
                    // timezone is involved.
                    const dIso = String(item.dataset.date || '').slice(0, 10);
                    if (activePeriod === 'today')       show = (dIso === hotelToday);
                    else if (activePeriod === 'week')   show = (dIso >= hotelWeekAgo);
                    else if (activePeriod === 'month')  show = (dIso.slice(0, 7) === hotelToday.slice(0, 7));
                    else if (activePeriod === 'year')   show = (dIso.slice(0, 4) === hotelToday.slice(0, 4));
                }

                item.dataset.filterMatch = show ? '1' : '0';
                item.classList.remove('review-filter-hidden');
            });

            const filtered = allItems.filter(item => item.dataset.filterMatch === '1');
            const pageCount = Math.max(1, Math.ceil(filtered.length / reviewsPerPage));
            reviewState.page = Math.max(1, Math.min(reviewState.page, pageCount));
            const start = (reviewState.page - 1) * reviewsPerPage;
            filtered.forEach((item, index) => {
                if (index < start || index >= start + reviewsPerPage) {
                    item.classList.add('review-filter-hidden');
                }
            });
            allItems.filter(item => item.dataset.filterMatch !== '1').forEach(item => {
                item.classList.add('review-filter-hidden');
            });
            updateRatingsCard(filtered);
            updateChart(filtered);
            renderPagination(filtered);
        }

        window.onload = function () {
            const allItems = Array.from(document.querySelectorAll('.review-item'));
            const initData = getRatingsData(allItems);

            document.getElementById('totalCount').textContent   = allItems.length;
            document.getElementById('visibleCount').textContent = allItems.length;
            updateRatingsCard(allItems);

            const barCtx = document.getElementById('barChart');
            ratingsChart = new Chart(barCtx, {
                type: 'bar',
                data: {
                    labels: ['1 Star','2 Stars','3 Stars','4 Stars','5 Stars'],
                    datasets: [{
                        label: 'Number of Reviews',
                        data: initData,
                        backgroundColor: ['#933310','#c0570a','#ffb60a','#a8c050','#4caf50']
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { display: false },
                        title: { display: true, text: 'Ratings Distribution', font: { size: 16 } }
                    },
                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
                }
            });

            // Rating filter
            document.querySelectorAll('.rating-filter').forEach(link => {
                link.addEventListener('click', function (e) {
                    e.preventDefault();
                    const rating = this.dataset.rating;
                    reviewState.page = 1;
                    const btn = document.getElementById('ratingFilterBtn');
                    btn.dataset.active = rating === '0' ? '' : rating;
                    btn.innerHTML = rating === '0'
                        ? '<i class="bi bi-star-fill" style="color:#ffb60a;"></i> All Rating'
                        : '<i class="bi bi-star-fill" style="color:#ffb60a;"></i> ' + rating + ' Star' + (rating > 1 ? 's' : '');
                    applyFilters();
                });
            });

            // Category filter
            document.querySelectorAll('.category-filter').forEach(link => {
                link.addEventListener('click', function (e) {
                    e.preventDefault();
                    const cat = this.dataset.category;
                    reviewState.page = 1;
                    const btn = document.getElementById('categoryFilterBtn');
                    btn.dataset.active = cat;
                    btn.textContent = cat ? cat.charAt(0).toUpperCase() + cat.slice(1) : 'All Category';
                    applyFilters();
                });
            });

            // Date filter
            document.querySelectorAll('.date-filter').forEach(link => {
                link.addEventListener('click', function (e) {
                    e.preventDefault();
                    const period = this.dataset.period;
                    reviewState.page = 1;
                    const btn = document.getElementById('dateFilterBtn');
                    btn.dataset.active = period;
                    btn.textContent = period ? this.textContent : 'All Time';
                    applyFilters();
                });
            });
            applyFilters();
        };
    </script>

    <!-- ═══════════════════════════════════════════════════════════════
         SENTIMENT ANALYSIS JS
    ════════════════════════════════════════════════════════════════ -->
    <script>
        // Helpers
        function sentimentBadge(s) {
            const map = {
                'Positive': 'badge-positive',
                'Neutral':  'badge-neutral',
                'Negative': 'badge-negative',
                'Mixed':    'badge-mixed',
                'N/A':      'badge-na'
            };
            return `<span class="sentiment-badge ${map[s] || 'badge-na'}">${s}</span>`;
        }

        function ringClass(s) {
            const map = {
                'Positive': 'sentiment-ring-positive',
                'Neutral':  'sentiment-ring-neutral',
                'Negative': 'sentiment-ring-negative',
                'Mixed':    'sentiment-ring-mixed'
            };
            return map[s] || 'sentiment-ring-neutral';
        }

        function runLegacySentimentAnalysis() {
            // Collect visible reviews
            const visibleItems = Array.from(document.querySelectorAll('.review-item'))
                .filter(el => el.style.display !== 'none');

            if (visibleItems.length === 0) {
                document.getElementById('sentimentLoading').style.display = 'none';
                document.getElementById('sentimentError').style.display   = 'flex';
                document.getElementById('sentimentErrorMsg').textContent  = 'No visible reviews to analyze. Please clear your filters and try again.';
                return;
            }

            const reviews = visibleItems.map((el, idx) => ({
                index:    idx + 1,
                name:     el.dataset.name     || 'Guest',
                room:     el.dataset.room     || '',
                rating:   el.dataset.rating   || '0',
                category: el.dataset.category || '',
                text:     el.dataset.text     || el.querySelector('.review-text')?.textContent?.trim() || '',
                date:     el.dataset.date     || ''
            }));

            // Reset UI
            document.getElementById('sentimentLoading').style.display = 'flex';
            document.getElementById('sentimentResults').style.display  = 'none';
            document.getElementById('sentimentError').style.display    = 'none';
            document.getElementById('sentimentSubtitle').textContent   =
                `Analyzing ${reviews.length} visible review${reviews.length !== 1 ? 's' : ''}…`;

            fetch('../admin/sentimentanalysis.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ reviews })
            })
            .then(res => res.json())
            .then(data => {
                document.getElementById('sentimentLoading').style.display = 'none';

                if (!data.success) {
                    document.getElementById('sentimentError').style.display   = 'flex';
                    document.getElementById('sentimentErrorMsg').textContent  = data.error || 'Unknown error.';
                    return;
                }

                const a = data.analysis;

                // Score ring
                const ring = document.getElementById('scoreRing');
                ring.textContent  = (a.sentiment_score ?? '—') + (a.sentiment_score != null ? '%' : '');
                ring.className    = 'sentiment-score-ring ' + ringClass(a.overall_sentiment);

                document.getElementById('overallLabel').innerHTML = sentimentBadge(a.overall_sentiment);
                document.getElementById('summaryText').textContent        = a.summary || '';
                document.getElementById('recommendationText').textContent = a.recommendation || '';
                document.getElementById('actionPlanList').innerHTML = (a.action_plan || []).map(item =>
                    `<li class="mb-1"><i class="bi bi-arrow-right-circle-fill me-2" style="color:#e6960a;"></i>${escapeMarleAIHtml(item)}</li>`
                ).join('') || '<li class="text-muted">No action plan available.</li>';
                document.getElementById('sentimentSubtitle').textContent  =
                    `${reviews.length} review${reviews.length !== 1 ? 's' : ''} analyzed`;
                document.getElementById('reviewCountLabel').textContent   =
                    `${reviews.length} review${reviews.length !== 1 ? 's' : ''} analyzed`;

                // Strengths
                const sl = document.getElementById('strengthsList');
                sl.innerHTML = (a.strengths || []).map(s =>
                    `<li class="mb-1"><i class="bi bi-check-circle-fill text-success me-2"></i>${s}</li>`
                ).join('') || '<li class="text-muted">None identified</li>';

                // Concerns
                const cl = document.getElementById('concernsList');
                cl.innerHTML = (a.concerns || []).map(c =>
                    `<li class="mb-1"><i class="bi bi-exclamation-circle-fill text-danger me-2"></i>${c}</li>`
                ).join('') || '<li class="text-muted">None identified</li>';

                // Category breakdown
                const catDiv = document.getElementById('categoryBreakdown');
                const catOrder = ['hotel','restaurant','amenities','service'];
                catDiv.innerHTML = catOrder.map(key => {
                    const cat = a.category_breakdown?.[key];
                    if (!cat) return '';
                    return `<div class="cat-row">
                        <div class="d-flex align-items-center gap-2">
                            <strong style="width:110px;">${cat.label}</strong>
                            ${sentimentBadge(cat.sentiment)}
                        </div>
                        <span class="text-muted" style="font-size:13px;">${cat.note || ''}</span>
                    </div>`;
                }).join('');

                // Per-review
                const prDiv = document.getElementById('perReviewList');
                const reviewMap = {};
                (a.reviews || []).forEach(r => { reviewMap[r.index] = r; });

                prDiv.innerHTML = reviews.map((rv, i) => {
                    const rData = reviewMap[i + 1] || {};
                    const keywords = (rData.keywords || []).map(k =>
                        `<span class="keyword-tag">${k}</span>`
                    ).join('');
                    return `<div class="review-row-sa">
                        <div style="min-width:30px; font-weight:600; color:#aaa;">#${i+1}</div>
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="fw-semibold">${rv.name}</span>
                                <small class="text-muted">Room ${rv.room} · ${rv.category}</small>
                                ${sentimentBadge(rData.sentiment || 'N/A')}
                            </div>
                            <p class="mb-1 text-muted" style="font-size:12px;">${rv.text.slice(0, 120)}${rv.text.length > 120 ? '…' : ''}</p>
                            <div>${keywords}</div>
                        </div>
                    </div>`;
                }).join('');

                document.getElementById('sentimentResults').style.display = 'block';
            })
            .catch(err => {
                document.getElementById('sentimentLoading').style.display = 'none';
                document.getElementById('sentimentError').style.display   = 'flex';
                document.getElementById('sentimentErrorMsg').textContent  = 'Network error: ' + err.message;
            });
        }

        function escapeMarleAIHtml(value) {
            return String(value ?? '').replace(/[&<>'"]/g, character => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
            }[character]));
        }

        function runSentimentAnalysis() {
            const limit = Math.max(1, Math.min(100, Number(document.getElementById('lunaLimit').value) || 100));
            const category = document.getElementById('lunaCategory').value;
            const period = document.getElementById('lunaPeriod').value;
            const language = document.getElementById('lunaLanguage').value;
            document.getElementById('sentimentLoading').style.display = 'flex';
            document.getElementById('sentimentResults').style.display = 'none';
            document.getElementById('sentimentError').style.display = 'none';
            document.getElementById('sentimentSubtitle').textContent = `Marle AI is processing up to ${limit} selected reviews in batches…`;

            fetch('../admin/sentimentanalysis.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ limit, category, period, language })
            })
            .then(response => response.json())
            .then(data => {
                document.getElementById('sentimentLoading').style.display = 'none';
                if (!data.success) {
                    document.getElementById('sentimentError').style.display = 'flex';
                    document.getElementById('sentimentErrorMsg').textContent = data.error || 'Marle AI analysis failed.';
                    return;
                }

                const pipeline = data.pipeline || {};
                const report = pipeline.report || {};
                const summary = pipeline.summary || {};
                const results = pipeline.results || [];
                const average = summary.average_score == null ? null : Number(summary.average_score);
                const score = report.sentiment_score ?? (average == null ? null : Math.round((average + 1) * 50));
                const overall = report.overall_sentiment || 'N/A';
                const sentimentCounts = summary.sentiments || {};
                const themeCounts = summary.themes || {};
                const topThemes = Object.keys(themeCounts).slice(0, 3);

                document.getElementById('scoreRing').textContent = score == null ? '—' : score + '%';
                document.getElementById('scoreRing').className = 'sentiment-score-ring ' + ringClass(overall);
                document.getElementById('overallLabel').innerHTML = sentimentBadge(overall);
                document.getElementById('summaryText').textContent = report.summary || 'Marle AI has no report summary for this selection.';
                document.getElementById('recommendationText').textContent = report.recommendation || 'No recommendation is available for this selection.';
                document.getElementById('actionPlanList').innerHTML = (report.action_plan || []).map(item => `<li class="mb-1"><i class="bi bi-arrow-right-circle-fill me-2" style="color:#e6960a;"></i>${escapeMarleAIHtml(item)}</li>`).join('') || '<li class="text-muted">No action plan available.</li>';
                document.getElementById('sentimentSubtitle').textContent = `${results.length} selected review results available`;
                document.getElementById('reviewCountLabel').textContent = `${results.length} latest results · ${pipeline.newly_processed || 0} new API classifications`;

                document.getElementById('strengthsList').innerHTML = (report.strengths || []).map(item => `<li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>${escapeMarleAIHtml(item)}</li>`).join('') || '<li class="text-muted">No strengths identified.</li>';
                document.getElementById('concernsList').innerHTML = (report.concerns || []).map(item => `<li class="mb-2"><i class="bi bi-exclamation-circle-fill text-danger me-2"></i>${escapeMarleAIHtml(item)}</li>`).join('') || '<li class="text-muted">No concerns identified.</li>';
                document.getElementById('categoryBreakdown').innerHTML = Object.entries(report.category_breakdown || {}).map(([name, details]) => `<div class="cat-row"><div><strong>${escapeMarleAIHtml(name)}</strong><small class="text-muted ms-2">${details.count} reviews · avg ${details.average_score}</small></div><span class="text-muted">${details.positive} positive · ${details.neutral} neutral · ${details.negative} negative · theme: ${escapeMarleAIHtml(details.top_theme || 'other')}</span></div>`).join('') || '<span class="text-muted">No category results yet.</span>';
                document.getElementById('perReviewList').innerHTML = results.map(review => `<div class="review-row-sa"><div style="min-width:30px;font-weight:600;color:#aaa;">#${review.id}</div><div class="flex-grow-1"><div class="d-flex align-items-center gap-2 mb-1"><span class="fw-semibold">${escapeMarleAIHtml(review.guest_name || 'Guest')}</span><small class="text-muted">Room ${escapeMarleAIHtml(review.room_no)} · ${escapeMarleAIHtml(review.category)}</small>${sentimentBadge(review.sentiment.charAt(0).toUpperCase() + review.sentiment.slice(1))}</div><p class="mb-1 text-muted" style="font-size:12px;">${escapeMarleAIHtml(review.review_text).slice(0, 120)}${review.review_text.length > 120 ? '…' : ''}</p><small class="text-muted">Score ${Number(review.sentiment_score).toFixed(3)} · ${escapeMarleAIHtml(review.theme)}</small></div></div>`).join('');
                document.getElementById('sentimentResults').style.display = 'block';
            })
            .catch(error => {
                document.getElementById('sentimentLoading').style.display = 'none';
                document.getElementById('sentimentError').style.display = 'flex';
                document.getElementById('sentimentErrorMsg').textContent = 'Network error: ' + error.message;
            });
        }

        // Trigger on modal open
        document.getElementById('sentimentModal').addEventListener('show.bs.modal', function () {
            document.getElementById('sentimentLoading').style.display = 'none';
            document.getElementById('sentimentResults').style.display = 'none';
            document.getElementById('sentimentError').style.display = 'none';
            document.getElementById('sentimentSubtitle').textContent = 'Choose a review scope, then start the analysis.';
        });

        // Re-run button
        document.getElementById('rerunBtn').addEventListener('click', function () {
            runSentimentAnalysis();
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>
    <script src="../admin/includes/notif_poll.js"></script>
</body>

</html>


