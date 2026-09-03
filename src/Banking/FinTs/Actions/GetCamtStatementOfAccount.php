<?php

declare(strict_types=1);

namespace FilamentAccounting\Banking\FinTs\Actions;

use Fhp\Model\SEPAAccount;
use Fhp\Model\StatementOfAccount\StatementOfAccount;
use Fhp\PaginateableAction;
use Fhp\Protocol\BPD;
use Fhp\Protocol\Message;
use Fhp\Protocol\UnexpectedResponseException;
use Fhp\Protocol\UPD;
use Fhp\Segment\CAZ\HICAZSv1;
use Fhp\Segment\CAZ\HICAZv1;
use Fhp\Segment\CAZ\HKCAZv1;
use Fhp\Segment\CAZ\UnterstuetzteCamtMessages;
use Fhp\Segment\Common\Kti;
use Fhp\Segment\HIRMS\Rueckmeldungscode;
use Fhp\Segment\SPA\HISPAS;
use Fhp\UnsupportedException;
use FilamentAccounting\Banking\FinTs\Support\CamtStatementParser;

final class GetCamtStatementOfAccount extends PaginateableAction
{
    private SEPAAccount $account;

    private ?\DateTimeInterface $from = null;

    private ?\DateTimeInterface $to = null;

    private bool $allAccounts;

    /** @var list<string> */
    private array $bookedXml = [];

    /** @var list<string> */
    private array $pendingXml = [];

    private ?StatementOfAccount $statement = null;

    public static function create(
        SEPAAccount $account,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
        bool $allAccounts = false,
    ): self {
        if ($from !== null && $to !== null && $from > $to) {
            throw new \InvalidArgumentException('From-date must be before to-date');
        }

        $action = new self;
        $action->account = $account;
        $action->from = $from;
        $action->to = $to;
        $action->allAccounts = $allAccounts;

        return $action;
    }

    public function __serialize(): array
    {
        return [parent::__serialize(), $this->account, $this->from, $this->to, $this->allAccounts];
    }

    public function __unserialize(array $serialized): void
    {
        [$parent, $this->account, $this->from, $this->to, $this->allAccounts] = $serialized;
        parent::__unserialize($parent);
    }

    public function getStatement(): StatementOfAccount
    {
        $this->ensureDone();

        return $this->statement ?? new StatementOfAccount;
    }

    protected function createRequest(BPD $bpd, ?UPD $upd): HKCAZv1
    {
        if ($upd === null || ! $upd->isRequestSupportedForAccount($this->account, 'HKCAZ')) {
            throw new UnsupportedException('The bank does not support CAMT statements for this account.');
        }

        $parameters = $bpd->requireLatestSupportedParameters('HICAZS');
        if (! $parameters instanceof HICAZSv1) {
            throw new UnsupportedException('Unsupported HICAZS parameter segment.');
        }

        $descriptors = array_values(array_filter(
            $parameters->getParameter()->getUnterstuetzteCamtMessages()->camtDescriptor,
            fn (string $descriptor): bool => str_contains(strtolower($descriptor), 'camt.052'),
        ));
        if ($descriptors === []) {
            throw new UnsupportedException('The bank does not advertise a supported CAMT.052 statement format.');
        }

        if ($this->allAccounts && ! $parameters->getParameter()->getAlleKontenErlaubt()) {
            throw new \InvalidArgumentException('The bank does not permit allAccounts=true.');
        }

        $hispas = $bpd->requireLatestSupportedParameters('HISPAS');
        if (! $hispas instanceof HISPAS) {
            throw new UnsupportedException('The bank does not advertise SEPA account parameters.');
        }

        return HKCAZv1::create(
            Kti::fromAccount($this->account, $hispas->getParameter()->getNationaleKontoverbindungErlaubt()),
            UnterstuetzteCamtMessages::create($descriptors),
            $this->allAccounts,
            $this->dateTime($this->from),
            $this->dateTime($this->to),
        );
    }

    public function processResponse(Message $response)
    {
        parent::processResponse($response);

        if ($response->findRueckmeldung(Rueckmeldungscode::NICHT_VERFUEGBAR) === null) {
            /** @var list<HICAZv1> $segments */
            $segments = $response->findSegments(HICAZv1::class);
            if (count($segments) < count($this->getRequestSegmentNumbers())) {
                throw new UnexpectedResponseException('Missing HICAZ response segment.');
            }

            foreach ($segments as $segment) {
                foreach ($segment->getGebuchteUmsaetze() as $xml) {
                    $this->bookedXml[] = $xml;
                }

                if ($segment->nichtGebuchteUmsaetze !== null) {
                    $this->pendingXml[] = $segment->getNichtGebuchteUmsaetze();
                }
            }
        }

        if (! $this->hasMorePages()) {
            $this->statement = (new CamtStatementParser)->parse($this->bookedXml, $this->pendingXml);
        }
    }

    private function dateTime(?\DateTimeInterface $value): ?\DateTime
    {
        return match (true) {
            $value instanceof \DateTime => $value,
            $value !== null => \DateTime::createFromInterface($value),
            default => null,
        };
    }
}
