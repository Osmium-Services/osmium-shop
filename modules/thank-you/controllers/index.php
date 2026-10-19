<?php

// The paid order's figures, left in the session by checkout-action.php. Read once and cleared,
// so a refresh cannot report the same sale twice. Null when the visitor did not just pay.
$this->data['purchase'] = $_SESSION['purchase_event'] ?? null;
unset($_SESSION['purchase_event']);
