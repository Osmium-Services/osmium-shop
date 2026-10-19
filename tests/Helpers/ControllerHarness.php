<?php

declare(strict_types=1);

namespace Tests\Helpers;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Library\ResponseSentException;
use Osmium\Core\Library\StoreFinance;
use Osmium\Core\Library\PaymentProviders;

require_once \dirname(__DIR__, 4) . '/core/library/ResponseSentException.php';
require_once \dirname(__DIR__, 2) . '/models/StoreFinance.php';

/**
 * Runs a procedural controller file (the kind that uses $this->osmium and ends
 * in jsonSuccess()/jsonError()) against a real database and returns the JSON
 * it would have sent. $this->osmium is a stand-in with only what those
 * controllers touch; the real jsonSuccess()/jsonError() also throw
 * ResponseSentException, which is how the stand-in stops the controller.
 */
final class ControllerHarness
{
    /**
     * Every connection object a controller has run against, kept for the rest of the process. PDO rolls back
     * the shared persistent connection's open transaction when any object over it is freed, so letting an
     * earlier test's connection object go during a later test would silently end that test's transaction
     * and leave its rows behind.
     *
     * @var list<OsmiumPDO>
     */
    private static array $keepAlive = [];

    /**
     * @param array<string, mixed> $input JSON body the controller reads
     * @param array<string, mixed> $checkout The 'checkout' config block
     * @param array<string, mixed> $site The 'site' config block
     * @return array{ok: bool, body: array, status: int}
     */
    public static function run(
        string $controllerFile,
        array $input,
        OsmiumPDO $db,
        array $checkout = [],
        array $site = [],
        ?StoreFinance $finance = null,
        array $extraConfig = [],
    ): array {
        self::$keepAlive[] = $db;

        $osmium = new class (
            $input,
            $db,
            (object) ($extraConfig + ['checkout' => (object) $checkout, 'site' => (object) $site, 'shop' => (object) [], 'notifications' => (object) []]),
            $finance ?? new StoreFinance('GBP', '£', 'VAT', 20.0, 'GB'),
        ) {
            public ?array $response = null;

            public function __construct(
                private array $input,
                public OsmiumPDO $dataSource,
                public object $config,
                StoreFinance $finance,
            ) {
                StoreFinance::useFor($this, $finance);
            }

            public function getJsonInput(): array
            {
                return $this->input;
            }

            public function jsonSuccess(array $result, int $code = 200): void
            {
                $this->response = ['ok' => true, 'body' => $result, 'status' => $code];
                throw new ResponseSentException();
            }

            public function jsonError(string $message, int $code = 200, array $extra = []): void
            {
                $this->response = ['ok' => false, 'body' => ['success' => false, 'error' => $message] + $extra, 'status' => $code];
                throw new ResponseSentException();
            }

            public function imagePath(string $partialImagePath): string
            {
                return $partialImagePath;
            }
        };

        $host = new class ($osmium) {
            public function __construct(public object $osmium) {}

            public function run(string $file): void
            {
                include $file;
            }
        };

        try {
            $host->run($controllerFile);
        } catch (ResponseSentException) {
            // The controller answered
        }

        if ($osmium->response === null) throw new \LogicException('The controller finished without sending a response');

        return $osmium->response;
    }

    /**
     * Give the controller a PHP session with the given CSRF token. A session another test left started is
     * closed first, since its id cannot be changed while it is active.
     *
     * @return array{id: string, data: array} what endSession() needs to put things back
     */
    public static function beginSession(string $token): array
    {
        if (\session_status() === PHP_SESSION_ACTIVE) \session_write_close();

        $state = ['id' => \session_id(), 'data' => $_SESSION ?? []];
        \session_id('harness-' . \uniqid());
        $_SESSION = ['token' => $token];

        return $state;
    }

    /** @param array{id: string, data: array} $state from beginSession() */
    public static function endSession(array $state): void
    {
        $_SESSION = $state['data'];
        \session_id($state['id']);
    }

    /** Forget the provider list PaymentProviders::external() keeps for the rest of a request. */
    public static function resetProviderMemo(?array $providers = null): void
    {
        $memo = new \ReflectionProperty(PaymentProviders::class, 'externalMemo');
        $memo->setValue(null, $providers);
    }
}
