<?php

// The shared framework token (OsmiumController::setFormSecurityToken()) hasn't
// run yet at this point in the request — module controllers run first — so a
// first-time visitor would otherwise get an empty token here.
if (empty($_SESSION['token'])) $_SESSION['token'] = $this->osmium->password->generateToken();

$this->data['template']['pageTitle'] = 'Basket';
$this->data['checkout']['csrfToken'] = $_SESSION['token'];
