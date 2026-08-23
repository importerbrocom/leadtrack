<?php

require __DIR__ . '/_init.php';

use App\Admin\Session;

Session::logout();
Session::flash('You have been signed out', 'info');

redirect('login.php');
