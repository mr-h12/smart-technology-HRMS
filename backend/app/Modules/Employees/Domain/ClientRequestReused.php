<?php

declare(strict_types=1);

namespace App\Modules\Employees\Domain;

use RuntimeException;

/**
 * A replayed "new day laborer" request whose name, wage or phone differ from what its first send saved; rendered as 409
 * `client_request_reused` in bootstrap/app.php. The first send went through, so the manager is told so instead of the correction being
 * dropped in silence (review of #72; coding-standards §8 replays only the same request).
 */
final class ClientRequestReused extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A client request id was reused with a different payload');
    }
}
