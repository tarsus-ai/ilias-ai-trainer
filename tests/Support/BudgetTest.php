<?php

declare(strict_types=1);

namespace TarsusIlias\Tests\Support;

use PHPUnit\Framework\TestCase;
use TarsusIlias\Support\Budget;

/**
 * Pricing the month from the brain's own credit figure: credits beyond the
 * included amount, at the agreed price - and nothing for a customer Tarsus
 * bills directly. The brain counts fractions (a step can cost 1.57
 * credits), so the figures are floats rounded to two decimals.
 */
final class BudgetTest extends TestCase
{
    public function testNothingIsChargedWithinTheIncludedAmount(): void
    {
        $overage = Budget::overageOf(99999.0, 100000, 0.32);
        $this->assertSame(0.0, $overage['charged']);
        $this->assertSame(0.0, $overage['cost']);
        $this->assertSame(99999.0, $overage['used']);
        $this->assertSame(100000, $overage['included']);
    }

    public function testCreditsBeyondTheIncludedAmountArePricedPerCredit(): void
    {
        $overage = Budget::overageOf(100250.0, 100000, 0.32);
        $this->assertSame(250.0, $overage['charged'], 'KI-Credits über die 100.000 im Monat hinaus');
        $this->assertSame(80.0, $overage['cost'], '250 × 0,32 €');
    }

    public function testPayAsYouGrowChargesEveryCredit(): void
    {
        $overage = Budget::overageOf(1234.0, 0, 0.32);
        $this->assertSame(1234.0, $overage['charged']);
        $this->assertSame(394.88, $overage['cost']);
    }

    public function testFractionalCreditsFromTheBrainArePriced(): void
    {
        $overage = Budget::overageOf(1.57, 0, 0.32);
        $this->assertSame(1.57, $overage['used']);
        $this->assertSame(1.57, $overage['charged']);
        $this->assertSame(0.5, $overage['cost'], '1,57 × 0,32 € = 0,5024 €, rounded to the cent');
    }

    public function testACustomPriceAndAmountAreHonoured(): void
    {
        $overage = Budget::overageOf(600.0, 500, 0.25);
        $this->assertSame(100.0, $overage['charged']);
        $this->assertSame(25.0, $overage['cost']);
        $this->assertSame(500, $overage['included']);
    }

    public function testALicenseKeyCustomerSeesNoPartnerCost(): void
    {
        $overage = Budget::overageOf(100250.0, 100000, 0.32, true);
        $this->assertSame(250.0, $overage['charged'], 'the figure is still shown');
        $this->assertSame(0.0, $overage['cost'], 'but Tarsus bills it, not the partner');
    }

    public function testNegativeFiguresAreTreatedAsZero(): void
    {
        $overage = Budget::overageOf(10.0, -5, 0.32);
        $this->assertSame(0, $overage['included']);
        $this->assertSame(10.0, $overage['charged']);
    }
}
