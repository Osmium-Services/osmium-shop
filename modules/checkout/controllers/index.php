<?php

use Osmium\Core\Library\PaymentProviders;


$external = PaymentProviders::external($this->osmium);
$selectedProvider = PaymentProviders::configured($this->osmium->config->checkout, $external);

// The shared framework token (OsmiumController::setFormSecurityToken()) hasn't
// run yet at this point in the request — module controllers run first — so a
// first-time visitor would otherwise get an empty token here.
if (empty($_SESSION['token'])) $_SESSION['token'] = $this->osmium->password->generateToken();

$this->data['template']['pageTitle'] = 'Checkout - Payment';
$this->data['checkout']['csrfToken'] = $_SESSION['token'];
$this->data['checkout']['provider'] = $selectedProvider;

// A service-provided provider (Stripe, PayPal, etc.) supplies its own readiness and
// whatever the page needs to render it (e.g. a publishable key).
$externalSelected = $external[$selectedProvider] ?? null;
$this->data['checkout']['externalReady'] = $externalSelected !== null && $externalSelected['ready'];
$this->data['checkout']['providerData'] = $externalSelected['frontend'] ?? [];
$this->data['checkout']['paymentReady'] = $this->data['checkout']['externalReady'];
