<?php

declare(strict_types=1);

namespace PriceWatch\Service\Import;

/**
 * Which rung of the cascade attached the line. Stored so the back-office can
 * show how a variant was identified, and so a weak attachment can be audited.
 */
enum ResolutionMethod: string
{
    case PseId = 'pse_id';
    case Gtin = 'gtin';
    case Attributes = 'attributes';
    case PseRef = 'pse_ref';
    case OnlySaleElement = 'only_sale_element';
}
