<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Tests\Integration\Behaviour\Features\Context\Domain\Product\Combination;

use Behat\Gherkin\Node\TableNode;
use DateTime;
use Language;
use PHPUnit\Framework\Assert;
use PrestaShop\PrestaShop\Core\Domain\Product\Combination\Command\UpdateCombinationStockCommand;
use PrestaShop\PrestaShop\Core\Domain\Product\Combination\QueryResult\CombinationStock;
use PrestaShop\PrestaShop\Core\Domain\Product\Stock\Exception\ProductStockConstraintException;
use PrestaShop\PrestaShop\Core\Util\DateTime\DateTime as DateTimeUtil;
use RuntimeException;
use StockAvailable;
use Tests\Integration\Behaviour\Features\Context\Util\PrimitiveUtils;

class UpdateCombinationStockFeatureContext extends AbstractCombinationFeatureContext
{
    /**
     * @When I update combination :combinationReference stock with following details:
     *
     * @param string $combinationReference
     * @param TableNode $tableNode
     */
    public function updateStock(string $combinationReference, TableNode $tableNode): void
    {
        try {
            $command = new UpdateCombinationStockCommand($this->getSharedStorage()->get($combinationReference));
            $this->fillCommand($command, $tableNode->getRowsHash());

            $this->getCommandBus()->handle($command);
        } catch (ProductStockConstraintException $e) {
            $this->setLastException($e);
        }
    }

    /**
     * @Transform table:combination stock detail,value
     *
     * @param TableNode $tableNode
     *
     * @return CombinationStock
     */
    public function transformCombinationStock(TableNode $tableNode): CombinationStock
    {
        $dataRows = $tableNode->getRowsHash();

        return new CombinationStock(
            (int) $dataRows['quantity'],
            (int) $dataRows['minimal quantity'],
            (int) $dataRows['low stock threshold'],
            PrimitiveUtils::castStringBooleanIntoBoolean($dataRows['low stock alert is enabled']),
            $dataRows['location'],
            '' === $dataRows['available date'] ? null : new DateTime($dataRows['available date']),
            !empty($dataRows['available now labels']) ? $dataRows['available now labels'] : [],
            !empty($dataRows['available later labels']) ? $dataRows['available later labels'] : []
        );
    }

    /**
     * @Then combination :combinationReference should have :availableQuantity available items
     *
     * @param string $combinationReference
     * @param int $availableQuantity
     */
    public function assertCombinationAvailableQuantity(string $combinationReference, int $availableQuantity): void
    {
        $actualStock = $this->getCombinationForEditing($combinationReference, $this->getDefaultShopId())->getStock();
        Assert::assertSame(
            $availableQuantity,
            $actualStock->getQuantity(),
            sprintf('Unexpected combination "%s" quantity', $combinationReference)
        );
    }

    /**
     * @Then combination :combinationReference should have following stock details:
     *
     * @param string $combinationReference
     * @param CombinationStock $expectedStock
     */
    public function assertStockDetails(string $combinationReference, CombinationStock $expectedStock): void
    {
        $actualStock = $this->getCombinationForEditing($combinationReference, $this->getDefaultShopId())->getStock();

        Assert::assertSame(
            $expectedStock->getQuantity(),
            $actualStock->getQuantity(),
            sprintf('Unexpected combination "%s" quantity', $combinationReference)
        );
        Assert::assertSame(
            $expectedStock->getMinimalQuantity(),
            $actualStock->getMinimalQuantity(),
            sprintf('Unexpected combination "%s" minimal quantity', $combinationReference)
        );
        Assert::assertSame(
            $expectedStock->getLowStockThreshold(),
            $actualStock->getLowStockThreshold(),
            sprintf('Unexpected combination "%s" low stock threshold', $combinationReference)
        );
        Assert::assertSame(
            $expectedStock->isLowStockAlertEnabled(),
            $actualStock->isLowStockAlertEnabled(),
            sprintf('Unexpected combination "%s" low stock alert', $combinationReference)
        );
        Assert::assertSame(
            $expectedStock->getLocation(),
            $actualStock->getLocation(),
            sprintf('Unexpected combination "%s" location', $combinationReference)
        );
        if (null === $expectedStock->getAvailableDate()) {
            Assert::assertSame(
                $expectedStock->getAvailableDate(),
                $actualStock->getAvailableDate(),
                sprintf('Unexpected combination "%s" availability date. Expected NULL, got "%s"',
                    $combinationReference,
                    var_export($actualStock->getAvailableDate(), true)
                )
            );
        } else {
            Assert::assertEquals(
                $expectedStock->getAvailableDate()->format(DateTimeUtil::DEFAULT_DATETIME_FORMAT),
                $actualStock->getAvailableDate()->format(DateTimeUtil::DEFAULT_DATETIME_FORMAT),
                sprintf('Unexpected combination "%s" availability date', $combinationReference)
            );
        }
        $this->assertLocalizedProperty(
            $expectedStock->getLocalizedAvailableNowLabels(),
            $actualStock->getLocalizedAvailableNowLabels(),
            'available now label'
        );
        $this->assertLocalizedProperty(
            $expectedStock->getLocalizedAvailableLaterLabels(),
            $actualStock->getLocalizedAvailableLaterLabels(),
            'available later label'
        );
    }

    private function assertLocalizedProperty(array $expectedValues, array $actualValues, string $fieldName): void
    {
        foreach ($expectedValues as $langId => $expectedValue) {
            $langIso = Language::getIsoById($langId);

            if (!isset($actualValues[$langId])) {
                throw new RuntimeException(sprintf(
                    'Expected localized %s value is not set in %s language',
                    $fieldName,
                    $langIso
                ));
            }

            if ($expectedValue !== $actualValues[$langId]) {
                throw new RuntimeException(
                    sprintf(
                        'Expected %s in "%s" language was "%s", but got "%s"',
                        $fieldName,
                        $langIso,
                        var_export($expectedValue, true),
                        var_export($actualValues[$langId], true)
                    )
                );
            }
        }
    }

    /**
     * @Then I should get error that it is not allowed to perform update using both - delta and fixed quantity
     *
     * @return void
     */
    public function assertLastErrorIsDuplicateQuantityUpdate(): void
    {
        $this->assertLastErrorIs(
            ProductStockConstraintException::class,
            ProductStockConstraintException::FIXED_AND_DELTA_QUANTITY_PROVIDED
        );
    }

    /**
     * @param UpdateCombinationStockCommand $command
     * @param array<string, mixed> $dataRows
     */
    private function fillCommand(UpdateCombinationStockCommand $command, array $dataRows): void
    {
        if (isset($dataRows['delta quantity'])) {
            $command->setDeltaQuantity((int) $dataRows['delta quantity']);
        }
        if (isset($dataRows['fixed quantity'])) {
            $command->setFixedQuantity((int) $dataRows['fixed quantity']);
        }
        if (isset($dataRows['minimal quantity'])) {
            $command->setMinimalQuantity((int) $dataRows['minimal quantity']);
        }
        if (isset($dataRows['location'])) {
            $command->setLocation($dataRows['location']);
        }
        if (isset($dataRows['low stock threshold'])) {
            $command->setLowStockThreshold((int) $dataRows['low stock threshold']);
        }
        if (isset($dataRows['low stock alert is enabled'])) {
            $command->setLowStockAlert(PrimitiveUtils::castStringBooleanIntoBoolean($dataRows['low stock alert is enabled']));
        }
        if (isset($dataRows['available date'])) {
            $command->setAvailableDate(new DateTime($dataRows['available date']));
        }
        if (isset($dataRows['available now labels'])) {
            $command->setLocalizedAvailableNowLabels($dataRows['available now labels']);
            unset($dataRows['available now labels']);
        }
        if (isset($dataRows['available later labels'])) {
            $command->setLocalizedAvailableLaterLabels($dataRows['available later labels']);
            unset($dataRows['available later labels']);
        }
    }

    /**
     * @Then /^all combinations of product "([^"]*)" should have the stock policy to "([^"]*)"$/
     */
    public function allCombinationsOfProductShouldHaveTheStockPolicyTo(string $reference, string $outOfStock)
    {
        $product = $this->getProductForEditing($reference);

        $outOfStockInt = $this->convertOutOfStockToInt($outOfStock);
        Assert::assertSame(
            $product->getStockInformation()->getOutOfStockType(),
            $outOfStockInt
        );

        $combinations = $this->getCombinationsList($reference, $this->getDefaultShopId());

        foreach ($combinations->getCombinations() as $combination) {
            $id = StockAvailable::getStockAvailableIdByProductId(
                $this->getSharedStorage()->get($reference),
                $combination->getCombinationId()
            );

            Assert::assertSame(
                (int) (new StockAvailable($id))->out_of_stock,
                $outOfStockInt
            );
        }
    }
}
