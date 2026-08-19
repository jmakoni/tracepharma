<?php

namespace Tests\Unit\Support\Custody;

use App\Support\Custody\TerminalEpcDisposition;
use App\Support\Epcis\Validation\EpcisCbvAllowlist;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TerminalEpcDispositionTest extends TestCase
{
    #[Test]
    public function matches_every_terminal_disposition_as_a_cbv_uri_and_as_a_bare_local_name(): void
    {
        foreach (TerminalEpcDisposition::DISPOSITIONS as $local) {
            $this->assertTrue(
                TerminalEpcDisposition::isTerminal('urn:epcglobal:cbv:disp:'.$local),
                $local.' as a CBV URI',
            );

            $this->assertTrue(
                TerminalEpcDisposition::isTerminal($local),
                $local.' as a bare local name',
            );

            $this->assertTrue(
                TerminalEpcDisposition::isTerminal('  URN:EPCglobal:CBV:disp:'.strtoupper($local).' '),
                $local.' with partner casing and padding',
            );
        }
    }

    #[Test]
    public function ignores_dispositions_that_leave_the_unit_operable(): void
    {
        foreach ([null, '', 'active', 'in_progress', 'in_transit', 'encoded', 'reserved', 'returned'] as $disposition) {
            $this->assertFalse(
                TerminalEpcDisposition::isTerminal($disposition),
                var_export($disposition, true).' does not retire the identity',
            );
        }

        // A sold pack can be returned and shipped again, so commercial states are an
        // inventory question rather than a custody one.
        $this->assertFalse(TerminalEpcDisposition::isTerminal('urn:epcglobal:cbv:disp:retail_sold'));

        // "inactive" contains "active": the local name has to match whole, not by substring.
        $this->assertFalse(TerminalEpcDisposition::isTerminal('urn:epcglobal:cbv:disp:active'));
    }

    #[Test]
    public function matches_reads_the_disposition_off_latest_event_metadata(): void
    {
        $this->assertFalse(TerminalEpcDisposition::matches(null));

        $this->assertFalse(TerminalEpcDisposition::matches([
            'disposition' => 'urn:epcglobal:cbv:disp:in_progress',
        ]));

        $this->assertTrue(TerminalEpcDisposition::matches([
            'disposition' => 'urn:epcglobal:cbv:disp:destroyed',
        ]));

        // An event with no disposition at all says nothing about retirement.
        $this->assertFalse(TerminalEpcDisposition::matches(['gln' => '0366159000034']));
    }

    #[Test]
    public function every_terminal_disposition_is_one_the_validator_accepts_on_inbound_traffic(): void
    {
        foreach (TerminalEpcDisposition::DISPOSITIONS as $local) {
            $this->assertTrue(
                EpcisCbvAllowlist::isAllowedDisposition('urn:epcglobal:cbv:disp:'.$local),
                $local.' is on the CBV allowlist, so a partner can send it and we must honour it',
            );
        }
    }

    #[Test]
    public function sql_condition_binds_one_placeholder_per_disposition(): void
    {
        [$sql, $bindings] = TerminalEpcDisposition::eventCondition();

        $this->assertSame(substr_count($sql, '?'), count($bindings));
        $this->assertSame(TerminalEpcDisposition::DISPOSITIONS, $bindings);
        $this->assertStringContainsString('ev.disposition', $sql);

        // Wrapping in NOT(...) is the only way callers use it, so the comparison may
        // never evaluate to NULL for an event without a disposition.
        [$aliasedSql] = TerminalEpcDisposition::eventCondition('anc1ev');
        $this->assertStringContainsString('anc1ev.disposition', $aliasedSql);
        $this->assertStringContainsString('COALESCE', $aliasedSql);
    }

    #[Test]
    public function labels_the_state_the_way_the_operator_sees_it_elsewhere(): void
    {
        $this->assertSame('destroyed', TerminalEpcDisposition::label('urn:epcglobal:cbv:disp:destroyed'));
        $this->assertSame('recalled', TerminalEpcDisposition::label('recalled'));
        $this->assertSame('decommissioned', TerminalEpcDisposition::label(null));
    }
}
