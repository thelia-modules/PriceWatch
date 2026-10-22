<?php

declare(strict_types=1);

namespace PriceWatch\Service\Proposal;

/**
 * Why a price is put forward for a change, most urgent first: the order of
 * the cases is the order of the tabs.
 */
enum ProposalKind: string
{
    /** Dearer than a competitor and selling less: what was asked first (2026-10-20). */
    case LowerDeclining = 'lower_declining';

    /** Dearer than a competitor, sales steady, rising or too thin to tell. */
    case Lower = 'lower';

    /** The competitor sells below our margin floor: we can only come closer. */
    case FloorBlocked = 'floor_blocked';

    /** Our price today is already below the margin floor. */
    case MarginAlert = 'margin_alert';
}
