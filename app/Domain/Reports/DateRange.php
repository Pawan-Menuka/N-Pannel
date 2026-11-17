<?php

namespace App\Domain\Reports;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class DateRange
{
    public CarbonImmutable $from;

    public CarbonImmutable $to;

    public function __construct(CarbonImmutable $from, CarbonImmutable $to)
    {
        $this->from = $from->setTimezone('Asia/Colombo')->startOfDay();
        $this->to = $to->setTimezone('Asia/Colombo')->startOfDay();

        if ($this->from->greaterThan($this->to) || $this->from->addDays(365)->lessThan($this->to)) {
            throw new InvalidArgumentException('Choose an inclusive date range of no more than 366 days.');
        }
    }

    /** @return array{string, string} */
    public function bounds(): array
    {
        return [$this->from->format('Y-m-d H:i:s'), $this->to->addDay()->format('Y-m-d H:i:s')];
    }

    /** @return list<string> */
    public function days(): array
    {
        $days = [];
        for ($date = $this->from; $date->lessThanOrEqualTo($this->to); $date = $date->addDay()) {
            $days[] = $date->toDateString();
        }

        return $days;
    }
}
