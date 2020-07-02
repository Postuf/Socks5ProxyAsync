<?php

/** @noinspection PhpUnused */
/** @noinspection SpellCheckingInspection */

namespace SocksProxyAsync\DNS;

class dnsResponse
{
    private const C64K = 65536;
    public const RESULTTYPE_RESOURCE = 'resource';
    public const RESULTTYPE_NAMESERVER = 'nameserver';
    public const RESULTTYPE_ADDITIONAL = 'additional';

    /** @var int */
    protected $responsecounter;
    /** @var dnsResult[] */
    protected $resourceResults;
    /** @var dnsResult[] */
    protected $nameserverResults;
    /** @var dnsResult[] */
    protected $additionalResults;
    /** @var int */
    protected $resourceResponses;
    /** @var int */
    protected $nameserverResponses;
    /** @var int */
    protected $additionalResponses;
    /** @var array */
    protected $queries;
    private $questions;
    private $answers;
    private $authorative;
    private $truncated;
    private $recursionRequested;
    private $recursionAvailable;
    private $authenticated;
    private $dnssecAware;

    public function __construct()
    {
        $this->authorative = false;
        $this->truncated = false;
        $this->recursionRequested = false;
        $this->recursionAvailable = false;
        $this->authenticated = false;
        $this->dnssecAware = false;
        $this->responsecounter = 12;
        $this->queries = [];
        $this->resourceResults = [];
        $this->nameserverResults = [];
        $this->additionalResults = [];
    }

    public function addResult(dnsResult $result, string $recordtype): void
    {
        switch ($recordtype) {
            case self::RESULTTYPE_RESOURCE:
                $this->resourceResults[] = $result;
                break;
            case self::RESULTTYPE_NAMESERVER:
                $this->nameserverResults[] = $result;
                break;
            case self::RESULTTYPE_ADDITIONAL:
                $this->additionalResults[] = $result;
                break;
            default:
                break;
        }
    }

    public function addQuery($query): void
    {
        $this->queries[] = $query;
    }

    public function getQueries(): array
    {
        return $this->queries;
    }

    public function setAnswerCount($count): void
    {
        $this->answers = $count;
    }

    public function getAnswerCount()
    {
        return $this->answers;
    }

    public function setQueryCount($count): void
    {
        $this->questions = $count;
    }

    public function getQueryCount()
    {
        return $this->questions;
    }

    public function setAuthorative($flag): void
    {
        $this->authorative = $flag;
    }

    public function getAuthorative(): bool
    {
        return $this->authorative;
    }

    public function setTruncated($flag): void
    {
        $this->truncated = $flag;
    }

    public function getTruncated(): bool
    {
        return $this->truncated;
    }

    public function setRecursionRequested($flag): void
    {
        $this->recursionRequested = $flag;
    }

    public function getRecursionRequested(): bool
    {
        return $this->recursionRequested;
    }

    public function setRecursionAvailable($flag): void
    {
        $this->recursionAvailable = $flag;
    }

    public function getRecursionAvailable(): bool
    {
        return $this->recursionAvailable;
    }

    public function setAuthenticated($flag): void
    {
        $this->authenticated = $flag;
    }

    public function getAuthenticated(): bool
    {
        return $this->authenticated;
    }

    public function setDnssecAware($flag): void
    {
        $this->dnssecAware = $flag;
    }

    public function getDnssecAware(): bool
    {
        return $this->dnssecAware;
    }

    public function getResourceResults(): array
    {
        return $this->resourceResults;
    }

    public function getNameserverResults(): array
    {
        return $this->nameserverResults;
    }

    public function getAdditionalResults(): array
    {
        return $this->additionalResults;
    }

    public function ReadResponse($buffer, $count = 1, $offset = ''): string
    {
        /** @noinspection TypeUnsafeComparisonInspection */
        if ($offset == '') { // no offset so use and increment the ongoing counter
            $return = (string) substr($buffer, $this->responsecounter, $count);
            $this->responsecounter += $count;
        } else {
            $return = (string) substr($buffer, $offset, $count);
        }

        return $return;
    }

    /**
     * @param string $buffer
     * @param string $resulttype
     *
     * @throws dnsException
     * @noinspection TypeUnsafeComparisonInspection
     */
    public function ReadRecord(string $buffer, string $resulttype = ''): void
    {
        $domain = $this->ReadDomainLabel($buffer);
        $ans_header_bin = $this->ReadResponse($buffer, 10); // 10 byte header
        $ans_header = unpack('ntype/nclass/Nttl/nlength', $ans_header_bin);
        $types = new DNSTypes();
        $typeId = $types->getById((int) $ans_header['type']);
        switch ($typeId) {
            case 'A':
                $result = new dnsAresult(implode('.', unpack('Ca/Cb/Cc/Cd', $this->ReadResponse($buffer, 4))));
                break;

            case 'NS':
                $result = new dnsNSresult($this->ReadDomainLabel($buffer));
                break;

            case 'PTR':
                $result = new dnsPTRresult($this->ReadDomainLabel($buffer));
                break;

            case 'CNAME':
                $result = new dnsCNAMEresult($this->ReadDomainLabel($buffer));
                break;

            case 'MX':
                $result = new dnsMXresult();
                $prefs = $this->ReadResponse($buffer, 2);
                $prefs = unpack('nprio', $prefs);
                $result->setPrio($prefs['prio']);
                $result->setServer($this->ReadDomainLabel($buffer));
                break;

            case 'SOA':
                $result = new dnsSOAresult();
                $result->setNameserver($this->ReadDomainLabel($buffer));
                $result->setResponsible($this->ReadDomainLabel($buffer));
                $buffer = $this->ReadResponse($buffer, 20);
                $extras = unpack('Nserial/Nrefresh/Nretry/Nexpiry/Nminttl', $buffer);
                $result->setSerial($extras['serial']);
                $result->setRefresh($extras['refresh']);
                $result->setRetry($extras['retry']);
                $result->setExpiry($extras['expiry']);
                $result->setMinttl($extras['minttl']);
                break;

            case 'TXT':
                $result = new dnsTXTresult($this->ReadResponse($buffer, $ans_header['length']));
                break;

            case 'DS':
                $stuff = $this->ReadResponse($buffer, $ans_header['length']);
                $length = (($ans_header['length'] - 4) * 2) - 8;
                $stuff = unpack('nkeytag/Calgo/Cdigest/H'.$length.'string/H*rest', $stuff);
                $stuff['string'] = strtoupper($stuff['string']);
                $stuff['rest'] = strtoupper($stuff['rest']);
                $result = new dnsDSresult($stuff['keytag'], $stuff['algo'], $stuff['digest'], $stuff['string'], $stuff['rest']);
                break;

            case 'DNSKEY':
                $stuff = $this->ReadResponse($buffer, $ans_header['length']);
                $this->keytag($stuff, $ans_header['length']);
                $this->keytag2($stuff, $ans_header['length']);
                $extras = unpack('nflags/Cprotocol/Calgorithm/a*pubkey', $stuff);
                $flags = sprintf("%016b\n", $extras['flags']);
                $result = new dnsDNSKEYresult($extras['flags'], $extras['protocol'], $extras['algorithm'], $extras['pubkey']);
                $result->setKeytag($this->keytag($stuff, $ans_header['length']));
                if ($flags[7] == '1') {
                    $result->setZoneKey(true);
                }
                if ($flags[15] == '1') {
                    $result->setSep(true);
                }
                break;

            case 'RRSIG':
                $stuff = $this->ReadResponse($buffer, 18);
                $test = unpack('ntype/calgorithm/clabels/Noriginalttl/Nexpiration/Ninception/nkeytag', $stuff);
                $result = new dnsRRSIGresult($test['type'], $test['algorithm'], $test['labels'], $test['originalttl'], $test['expiration'], $test['inception'], $test['keytag']);
                $name = $this->ReadDomainLabel($buffer);
                $result->setSignername($name);
                $sig = $this->ReadResponse($buffer, $ans_header['length'] - (strlen($name) + 2) - 18);
                $result->setSignature($sig);
                $result->setSignatureBase64(base64_encode($sig));
                break;

            default: // something we can't deal with
                $result = new dnsResult();
                $stuff = $this->ReadResponse($buffer, $ans_header['length']);
                $result->setData($stuff);
                break;

        }
        $result->setDomain($domain);
        $result->setType($ans_header['type']);
        $result->setTypeId($typeId);
        $result->setClass($ans_header['class']);
        $result->setTtl($ans_header['ttl']);
        $this->addResult($result, $resulttype);
    }

    private function keytag($key, $keysize): int
    {
        $ac = 0;
        for ($i = 0; $i < $keysize; $i++) {
            $keyp = unpack('C', $key[$i]);
            $ac += (($i & 1) ? $keyp[1] : $keyp[1] << 8);
        }
        $ac += ($ac >> 16) & 0xFFFF;

        return $ac & 0xFFFF;
    }

    private function keytag2($key, $keysize): int
    {
        $ac = 0;
        for ($i = 0; $i < $keysize; $i++) {
            $keyp = unpack('C', $key[$i]);
            $ac += ($i % 2 === 1 ? $keyp[1] : 256 * $keyp[1]);
        }
        $ac += ($ac / self::C64K) % self::C64K;

        return $ac % self::C64K;
    }

    private function ReadDomainLabel($buffer): string
    {
        $count = 0;
        $labels = $this->ReadDomainLabels($buffer, $this->responsecounter, $count);
        $domain = implode('.', $labels);
        $this->responsecounter += $count;

        return $domain;
    }

    private function ReadDomainLabels($buffer, $offset, &$counter = 0): array
    {
        $labels = [];
        $startoffset = $offset;
        $return = false;
        while (!$return) {
            $label_len = ord($this->ReadResponse($buffer, 1, $offset++));
            if ($label_len <= 0) {
                $return = true;
            } // end of data
            elseif ($label_len < 64) { // uncompressed data
                $labels[] = $this->ReadResponse($buffer, $label_len, $offset);
                $offset += $label_len;
            } else { // label_len>=64 -- pointer
                $nextitem = $this->ReadResponse($buffer, 1, $offset++);
                $pointer_offset = (($label_len & 0x3f) << 8) + ord($nextitem);
                // Branch Back Upon Ourselves...
                $pointer_labels = $this->ReadDomainLabels($buffer, $pointer_offset);
                foreach ($pointer_labels as $ptr_label) {
                    $labels[] = $ptr_label;
                }
                $return = true;
            }
        }
        $counter = (int) ($offset - $startoffset);

        return $labels;
    }

    public function setResourceResultCount(int $count): void
    {
        $this->resourceResponses = $count;
    }

    public function getResourceResultCount(): int
    {
        return $this->resourceResponses;
    }

    public function setNameserverResultCount(int $count): void
    {
        $this->nameserverResponses = $count;
    }

    public function getNameserverResultCount(): int
    {
        return $this->nameserverResponses;
    }

    public function setAdditionalResultCount(int $count): void
    {
        $this->additionalResponses = $count;
    }

    public function getAdditionalResultCount(): int
    {
        return $this->additionalResponses;
    }
}
