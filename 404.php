<?php
session_start();

$redirectUrl = 'login.php';
$buttonText  = 'Return to Login';

if (isset($_SESSION['usertype'])) {
    if ($_SESSION['usertype'] === 'superadmin') {
        $redirectUrl = 'superadmin/adminDB.php';
        $buttonText  = 'Back to Superadmin Dashboard';
    } elseif ($_SESSION['usertype'] === 'admin') {
        $redirectUrl = 'admin/adminDB.php';
        $buttonText  = 'Back to Admin Dashboard';
    } elseif ($_SESSION['usertype'] === 'employee') {
        $redirectUrl = 'employee/adminDB.php';
        $buttonText  = 'Back to Employee Dashboard';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
    <link rel="icon" href="img/reception.png">
    <title>Error</title>

    <style>
        body {
            background-image: url(img/MarleHotelFront.jpg);
            height: 100vh;
            background-position: center;
            background-repeat: no-repeat;
            background-size: cover;
            position: relative;

            background-attachment: fixed;
        }
        /* .row {
            margin-top:80px;
        } */
        h1 {
            margin-top: 30px;
            margin-left: 50px;
            color:#906441
        }
        a {
            margin-left: 50px;
            margin-bottom: 30px;
        }
        .btn {
            color: #fff;
            background-color: #daad49;
            padding: 10px 80px;
            font-size: 18px;
            transition: width 3s, background-color 0.6s;
        }
        .btn:hover {
            background-color: #e4e3e4;
            color: #906441;
        }
        .col {
            margin-top: 100px;
            margin-left: 80px;
            width: 2px;
            box-shadow: 0px 0px 3px 3px #dad7cd;
            background-color: #dad7cde2;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row">
            <!-- <div class="col-1"></div> -->
        <!-- </div> -->
        <div class="col">
            <h1>Sorry, you are not authorized to access this page.</h1>
            <br>
            <br>
            <a href="<?php echo htmlspecialchars($redirectUrl); ?>" class="btn"><?php echo htmlspecialchars($buttonText); ?></a>
        </div>
        <!-- <div class="col-1"></div> -->
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</body>
</html>
