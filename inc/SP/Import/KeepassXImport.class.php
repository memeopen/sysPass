<?php
/**
 * sysPass
 *
 * @author    nuxsmin
 * @link      http://syspass.org
 * @copyright 2012-2015 Rubén Domínguez nuxsmin@syspass.org
 *
 * This file is part of sysPass.
 *
 * sysPass is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * sysPass is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with sysPass.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace SP\Import;

use SimpleXMLElement;
use SP\Account\AccountData;
use SP\Core\Crypt;
use SP\Core\SPException;

defined('APP_ROOT') || die(_('No es posible acceder directamente a este archivo'));

/**
 * Esta clase es la encargada de importar cuentas desde KeePassX
 */
class KeepassXImport extends XmlImportBase
{
    /**
     * @var int
     */
    private $customerId = 0;
    /**
     * @var int
     */
    private $categoryId = 0;

    /**
     * Iniciar la importación desde KeePassX.
     *
     * @throws SPException
     * @return bool
     */
    public function doImport()
    {
        $this->setCustomerName('KeePassX');
        $this->customerId = $this->addCustomer();

        self::processCategories($this->xml);
    }

    /**
     * Obtener los grupos y procesar lan entradas de KeePass.
     *
     * @param SimpleXMLElement $xml con objeto XML del archivo de KeePass
     */
    protected function processCategories(SimpleXMLElement $xml)
    {
        foreach ($xml as $node) {
            if ($node->group) {
                foreach ($node->group as $group) {
                    // Analizar grupo
                    if ($node->group->entry) {
                        // Crear la categoría
                        $this->setCategoryName($group->title);
                        $this->categoryId = $this->addCategory();

                        // Crear cuentas
                        $this->processAccounts($group->entry);
                    }

                    if ($group->group) {
                        // Analizar subgrupo
                        $this->processCategories($group);
                    }
                }
            }

            if ($node->entry) {
                // Crear la categoría
                $this->setCategoryName($node->title);
                $this->categoryId = $this->addCategory();

                // Crear cuentas
                $this->processAccounts($node->entry);
            }
        }
    }

    /**
     * Obtener los datos de las entradas de KeePass.
     *
     * @param SimpleXMLElement $entries El objeto XML con las entradas
     */
    protected function processAccounts(SimpleXMLElement $entries)
    {
        foreach ($entries as $entry) {
            $notes = (isset($entry->comment)) ? (string)$entry->comment : '';
            $password = (isset($entry->password)) ? (string)$entry->password : '';
            $name = (isset($entry->title)) ? (string)$entry->title : '';
            $url = (isset($entry->url)) ? (string)$entry->url : '';
            $username = (isset($entry->username)) ? (string)$entry->username : '';

            $passData = Crypt::encryptData($password);

            $AccountData = new AccountData();
            $AccountData->setAccountPass($passData['data']);
            $AccountData->setAccountIV($passData['iv']);
            $AccountData->setAccountNotes($notes);
            $AccountData->setAccountName($name);
            $AccountData->setAccountUrl($url);
            $AccountData->setAccountLogin($username);
            $AccountData->setAccountCustomerId($this->customerId);
            $AccountData->setAccountCategoryId($this->categoryId);

            $this->addAccount($AccountData);
        }
    }
}