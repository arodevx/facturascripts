<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2018-2019 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
namespace FacturaScripts\Core\Lib\Accounting;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Lib\Accounting\AccountingCreateTools;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\Cuenta;
use FacturaScripts\Dinamic\Model\CuentaBanco;
use FacturaScripts\Dinamic\Model\Ejercicio;
use FacturaScripts\Dinamic\Model\GrupoClientes;
use FacturaScripts\Dinamic\Model\Impuesto;
use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Dinamic\Model\Subcuenta;

/**
 * Class for calculate/obtain accounting sub-account of:
 * (Respecting the additional levels)
 *
 *   - Customer
 *   - Customer Group
 *   - Supplier
 *   - Payment
 *
 * @author Artex Trading sa     <jcuello@artextrading.com>
 * @author Carlos García Gómez  <carlos@facturascripts.com>
 */
class AccountingAccounts
{

    const SPECIAL_CUSTOMER_ACCOUNT = 'CLIENT';
    const SPECIAL_EXPENSE_ACCOUNT = 'GTOBAN';
    const SPECIAL_PAYMENT_ACCOUNT = 'CAJA';
    const SPECIAL_SUPPLIER_ACCOUNT = 'PROVEE';
    const SPECIAL_TAX_IMPACTED_ACCOUNT = 'IVAREP';
    const SPECIAL_TAX_SUPPORTED_ACCOUNT = 'IVASOP';

    /**
     *
     * @var Ejercicio
     */
    public $exercise;

    /**
     * Class constructor
     */
    public function __construct()
    {
        $this->exercise = new Ejercicio();
    }

    /**
     * Get the accounting sub-account for the customer and the fiscal year.
     *   - First check the customer
     *   - Second check the customer group
     *   - Third search for the sub-account classified as a special account for customers
     *   - Fourth search for the general account classified as a special account for customers
     *     and then search for a sub account belonging to the account
     *
     * @param Cliente $customer
     * @param string  $specialAccount
     *
     * @return Subcuenta
     */
    public function getCustomerAccount(&$customer, string $specialAccount = self::SPECIAL_CUSTOMER_ACCOUNT)
    {
        /// defined sub-account code?
        if (!empty($customer->codsubcuenta)) {
            $subaccount = $this->getSubAccount($customer->codsubcuenta);
            if ($subaccount->exists()) {
                return $subaccount;
            }

            /// search parent account
            $account = $this->getSpecialAccount($specialAccount);

            /// create sub-account
            $createTools = new AccountingCreateTools();
            return $createTools->createBusinessAccount($customer, $account);
        }

        /// group has sub-account?
        $group = new GrupoClientes();
        if (!empty($customer->codgrupo) && $group->loadFromCode($customer->codgrupo)) {
            $groupSubaccount = $this->getCustomerGroupAccount($group, $specialAccount);
            if ($groupSubaccount->exists()) {
                return $groupSubaccount;
            }
        }

        /// create and assign a new sub-account code
        $account = $this->getSpecialAccount($specialAccount);
        $createTools = new AccountingCreateTools();
        return $createTools->createBusinessAccount($customer, $account);
    }

    /**
     * Get the accounting sub-account for the group's customer and the fiscal year.
     *
     * @param GrupoClientes $group
     * @param string        $specialAccount
     *
     * @return Subcuenta
     */
    public function getCustomerGroupAccount($group, string $specialAccount = self::SPECIAL_CUSTOMER_ACCOUNT)
    {
        if (empty($group->codsubcuenta)) {
            return new Subcuenta();
        }

        $subaccount = $this->getSubAccount($group->codsubcuenta);
        if ($subaccount->exists()) {
            return $subaccount;
        }

        /// search parent account
        $account = $this->getSpecialAccount($specialAccount);

        /// create in this exercise
        $createTools = new AccountingCreateTools();
        return $createTools->createFromAccount($account, $group->codsubcuenta, $group->nombre);
    }

    /**
     * Get the banking expenses sub-account for payments in the fiscal year.
     *
     * @param string $code
     * @param string $specialAccount
     *
     * @return Subcuenta
     */
    public function getExpenseAccount(string $code, string $specialAccount = self::SPECIAL_EXPENSE_ACCOUNT)
    {
        $bankAccount = new CuentaBanco();
        if ($bankAccount->loadFromCode($code) && !empty($bankAccount->codsubcuentagasto)) {
            return $this->getSubAccount($bankAccount->codsubcuentagasto);
        }

        return $this->getSpecialSubAccount($specialAccount);
    }

    /**
     * Get the accounting sub-account for payments in the fiscal year.
     *
     * @param string $code
     * @param string $specialAccount
     *
     * @return Subcuenta
     */
    public function getPaymentAccount(string $code, string $specialAccount = self::SPECIAL_PAYMENT_ACCOUNT)
    {
        $bankAccount = new CuentaBanco();
        if ($bankAccount->loadFromCode($code) && !empty($bankAccount->codsubcuenta)) {
            return $this->getSubAccount($bankAccount->codsubcuenta);
        }

        return $this->getSpecialSubAccount($specialAccount);
    }

    /**
     * Get the accounting account set as special.
     *
     * @param string $specialAccount
     *
     * @return Cuenta
     */
    public function getSpecialAccount(string $specialAccount)
    {
        $where = [
            new DataBaseWhere('codejercicio', $this->exercise->codejercicio),
            new DataBaseWhere('codcuentaesp', $specialAccount)
        ];
        $orderBy = ['codcuenta' => 'ASC'];

        $account = new Cuenta();
        $account->loadFromCode('', $where, $orderBy);
        return $account;
    }

    /**
     * Get an accounting sub-account for the special type indicated
     * If there is no, search within the group of accounts for the special type
     *
     * @param string $specialAccount
     *
     * @return Subcuenta
     */
    public function getSpecialSubAccount(string $specialAccount)
    {
        $where = [
            new DataBaseWhere('codejercicio', $this->exercise->codejercicio),
            new DataBaseWhere('codcuentaesp', $specialAccount)
        ];
        $orderBy = ['codsubcuenta' => 'ASC'];

        $subAccount = new Subcuenta();
        if ($subAccount->loadFromCode('', $where, $orderBy)) {
            return $subAccount;
        }

        $account = $this->getSpecialAccount($specialAccount);
        foreach ($account->getSubcuentas() as $subc) {
            return $subc;
        }

        return new Subcuenta();
    }

    /**
     * Get the indicated accounting sub-account.
     *
     * @param string $code
     *
     * @return Subcuenta
     */
    public function getSubAccount(string $code)
    {
        $where = [
            new DataBaseWhere('codejercicio', $this->exercise->codejercicio),
            new DataBaseWhere('codsubcuenta', $code)
        ];

        $subAccount = new Subcuenta();
        $subAccount->loadFromCode('', $where);
        return $subAccount;
    }

    /**
     * Get the accounting sub-account for the supplier and the fiscal year.
     * If it does not exist, search for the sub-account
     * associated with the special type indicated
     *
     * @param Proveedor $supplier
     * @param string    $specialAccount
     *
     * @return Subcuenta
     */
    public function getSupplierAccount($supplier, string $specialAccount = self::SPECIAL_SUPPLIER_ACCOUNT)
    {
        /// defined sub-account code?
        if (!empty($supplier->codsubcuenta)) {
            $subaccount = $this->getSubAccount($supplier->codsubcuenta);
            if ($subaccount->exists()) {
                return $subaccount;
            }

            /// search parent account
            $account = $this->getSpecialAccount($specialAccount);

            /// create sub-account
            $createTools = new AccountingCreateTools();
            return $createTools->createBusinessAccount($supplier, $account);
        }

        /// assign a new sub-account code
        $account = $this->getSpecialAccount($specialAccount);
        $createTools = new AccountingCreateTools();
        return $createTools->createBusinessAccount($supplier, $account);
    }

    /**
     * Get the accounting sub-account for Impacted Tax.
     *
     * @param Impuesto $tax
     * @param string $specialAccount
     * @return Subcuenta
     */
    public function getTaxImpactedAccount($tax, string $specialAccount = self::SPECIAL_TAX_IMPACTED_ACCOUNT)
    {
        return $this->getTaxAccount($tax->codsubcuentarep, $specialAccount);
    }

    /**
     * Get the accounting sub-account for Supported Tax.
     *
     * @param Impuesto $tax
     * @param string $specialAccount
     * @return Subcuenta
     */
    public function getTaxSupportedAccount($tax, string $specialAccount = self::SPECIAL_TAX_SUPPORTED_ACCOUNT)
    {
        return $this->getTaxAccount($tax->codsubcuentasop, $specialAccount);
    }

    /**
     * Get the accounting sub-account according to the indicated tax sub-account.
     *   - First check the tax
     *   - Second check the special account
     *
     * @param string $taxSubAccount
     * @param string $specialAccount
     * @return Subcuenta
     */
    private function getTaxAccount($taxSubAccount, string $specialAccount)
    {
        /// defined sub-account code?
        if (!empty($taxSubAccount)) {
            $subaccount = $this->getSubAccount($taxSubAccount);
            if ($subaccount->exists()) {
                return $subaccount;
            }

            /// search parent account
            $account = $this->getSpecialAccount($specialAccount);

            /// create sub-account
            $createTools = new AccountingCreateTools();
            return $createTools->createFromAccount($account, $taxSubAccount);
        }

        /// search from parent acount
        $account = $this->getSpecialAccount($specialAccount);
        $subaccount = new Subcuenta();
        $subaccount->loadFromCode('', [new DataBaseWhere('idcuenta', $account->idcuenta)], ['idsubcuenta' => 'ASC']);
        return $subaccount;
    }
}
