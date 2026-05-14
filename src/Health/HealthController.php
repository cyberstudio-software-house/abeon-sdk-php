<?php

declare(strict_types=1);

namespace Abeon\SDK\Health;

use Illuminate\Http\JsonResponse;

class HealthController
{
    /**
     * @param  iterable<Check>  $checks
     */
    public function __construct(private readonly iterable $checks)
    {
    }

    public function liveness(): JsonResponse
    {
        // If we got here, the PHP process is alive.
        return response()->json(['status' => CheckResult::STATUS_OK]);
    }

    public function readiness(): JsonResponse
    {
        $results = [];
        $overall = CheckResult::STATUS_OK;

        foreach ($this->checks as $check) {
            $result = $check->run();
            $results[$check->name()] = $result->toArray();

            if ($result->status === CheckResult::STATUS_DOWN) {
                $overall = CheckResult::STATUS_DOWN;
            } elseif (
                $result->status === CheckResult::STATUS_DEGRADED
                && $overall === CheckResult::STATUS_OK
            ) {
                $overall = CheckResult::STATUS_DEGRADED;
            }
        }

        return response()->json(
            ['status' => $overall, 'checks' => $results],
            $overall === CheckResult::STATUS_DOWN ? 503 : 200,
        );
    }
}
