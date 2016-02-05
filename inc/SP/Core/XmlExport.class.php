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

namespace SP\Core;

use SP\Account\AccountUtil;
use SP\Config\Config;
use SP\Log\Email;
use SP\Mgmt\Customer;
use SP\Log\Log;
use SP\Mgmt\Category;
use SP\Util\Util;

defined('APP_ROOT') || die(_('No es posible acceder directamente a este archivo'));

/**
 * Clase XmlExport para realizar la exportación de las cuentas de sysPass a formato XML
 *
 * @package SP
 */
class XmlExport
{
    /**
     * @var \DOMDocument
     */
    private $xml;
    /**
     * @var \DOMElement
     */
    private $root;
    /**
     * @var string
     */
    private $exportPass = null;
    /**
     * @var bool
     */
    private $encrypted = false;
    /**
     * @var string
     */
    private $exportDir = '';
    /**
     * @var string
     */
    private $exportFile = '';

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->xml = new \DOMDocument('1.0', 'UTF-8');
    }

    /**
     * Realiza la exportación de las cuentas a XML
     *
     * @param null $pass string La clave de exportación
     * @return bool
     */
    public static function doExport($pass = null)
    {
        $xml = new XmlExport();

        if (!is_null($pass) && !empty($pass)) {
            $xml->setExportPass($pass);
            $xml->setEncrypted(true);
        }

        $xml->setExportDir(Init::$SERVERROOT . DIRECTORY_SEPARATOR . 'backup');
        $xml->setExportFile();
        $xml->deleteOldExports();

        return $xml->makeXML();
    }

    /**
     * Establecer la clave de exportación
     *
     * @param string $exportPass
     */
    public function setExportPass($exportPass)
    {
        $this->exportPass = $exportPass;
    }

    /**
     * @param boolean $encrypted
     */
    public function setEncrypted($encrypted)
    {
        $this->encrypted = $encrypted;
    }

    /**
     * Crear el documento XML y guardarlo
     *
     * @return bool
     */
    public function makeXML()
    {
        $Log = new Log(_('Exportar XML'));

        try {
            $this->checkExportDir();
            $this->createRoot();
            $this->createMeta();
            $this->createCategories();
            $this->createCustomers();
            $this->createAccounts();
            $this->createHash();
            $this->writeXML();
        } catch (SPException $e) {
            $Log->setLogLevel(Log::ERROR);
            $Log->addDescription(_('Error al realizar la exportación de cuentas'));
            $Log->addDetails($e->getMessage(), $e->getHint());
            $Log->writeLog();

            Email::sendEmail($Log);
            return false;
        }

        $Log->addDescription(_('Exportación de cuentas realizada correctamente'));
        $Log->writeLog();

        Email::sendEmail($Log);

        return true;
    }

    /**
     * Crear el nodo raíz
     *
     * @throws SPException
     */
    private function createRoot()
    {
        try {
            $root = $this->xml->createElement('Root');
            $this->root = $this->xml->appendChild($root);
        } catch (\DOMException $e) {
            throw new SPException(SPException::SP_WARNING, $e->getMessage(), __FUNCTION__);
        }
    }

    /**
     * Crear el nodo con metainformación del archivo XML
     *
     * @throws SPException
     */
    private function createMeta()
    {
        try {
            $nodeMeta = $this->xml->createElement('Meta');
            $metaGenerator = $this->xml->createElement('Generator', 'sysPass');
            $metaVersion = $this->xml->createElement('Version', implode('.', Util::getVersion()));
            $metaTime = $this->xml->createElement('Time', time());
            $metaUser = $this->xml->createElement('User', Session::getUserLogin());
            $metaUser->setAttribute('id', Session::getUserId());
            $metaGroup = $this->xml->createElement('Group', Session::getUserGroupName());
            $metaGroup->setAttribute('id', Session::getUserGroupId());

            $nodeMeta->appendChild($metaGenerator);
            $nodeMeta->appendChild($metaVersion);
            $nodeMeta->appendChild($metaTime);
            $nodeMeta->appendChild($metaUser);
            $nodeMeta->appendChild($metaGroup);

            $this->root->appendChild($nodeMeta);
        } catch (\DOMException $e) {
            throw new SPException(SPException::SP_WARNING, $e->getMessage(), __FUNCTION__);
        }
    }

    /**
     * Crear el nodo con los datos de las categorías
     *
     * @throws SPException
     */
    private function createCategories()
    {
        $categories = Category::getCategories();

        if (count($categories) === 0) {
            return;
        }

        try {
            // Crear el nodo de categorías
            $nodeCategories = $this->xml->createElement('Categories');

            foreach ($categories as $category) {
                $categoryName = $this->xml->createElement('name', $this->escapeChars($category->category_name));
                $categoryDescription = $this->xml->createElement('description', $this->escapeChars($category->category_description));

                // Crear el nodo de categoría
                $nodeCategory = $this->xml->createElement('Category');
                $nodeCategory->setAttribute('id', $category->category_id);
                $nodeCategory->appendChild($categoryName);
                $nodeCategory->appendChild($categoryDescription);

                // Añadir categoría al nodo de categorías
                $nodeCategories->appendChild($nodeCategory);
            }

            $this->appendNode($nodeCategories);
        } catch (\DOMException $e) {
            throw new SPException(SPException::SP_WARNING, $e->getMessage(), __FUNCTION__);
        }
    }

    /**
     * Escapar carácteres no válidos en XML
     *
     * @param $data string Los datos a escapar
     * @return mixed
     */
    private function escapeChars($data)
    {
        $arrStrFrom = array("&", "<", ">", "\"", "\'");
        $arrStrTo = array("&#38;", "&#60;", "&#62;", "&#34;", "&#39;");

        return str_replace($arrStrFrom, $arrStrTo, $data);
    }

    /**
     * Añadir un nuevo nodo al árbol raíz
     *
     * @param \DOMElement $node El nodo a añadir
     * @throws SPException
     */
    private function appendNode(\DOMElement $node)
    {
        try {
            // Si se utiliza clave de encriptación los datos se encriptan en un nuevo nodo:
            // Encrypted -> Data
            if ($this->encrypted === true) {
                // Obtener el nodo en formato XML
                $nodeXML = $this->xml->saveXML($node);

                // Crear los datos encriptados con la información del nodo
                $encrypted = Crypt::mkEncrypt($nodeXML, $this->exportPass);
                $encryptedIV = Crypt::$strInitialVector;

                // Buscar si existe ya un nodo para el conjunto de datos encriptados
                $encryptedNode = $this->root->getElementsByTagName('Encrypted')->item(0);

                if (!$encryptedNode instanceof \DOMElement) {
                    $encryptedNode = $this->xml->createElement('Encrypted');
                }

                // Crear el nodo hijo con los datos encriptados
                $encryptedData = $this->xml->createElement('Data', base64_encode($encrypted));

                $encryptedDataIV = $this->xml->createAttribute('iv');
                $encryptedDataIV->value = base64_encode($encryptedIV);

                // Añadir nodos de datos
                $encryptedData->appendChild($encryptedDataIV);
                $encryptedNode->appendChild($encryptedData);

                // Añadir el nodo encriptado
                $this->root->appendChild($encryptedNode);
            } else {
                $this->root->appendChild($node);
            }
        } catch (\DOMException $e) {
            throw new SPException(SPException::SP_WARNING, $e->getMessage(), __FUNCTION__);
        }
    }

    /**
     * Crear el nodo con los datos de los clientes
     *
     * #@throws SPException
     */
    private function createCustomers()
    {
        $customers = Customer::getCustomers();

        if (count($customers) === 0) {
            return;
        }

        try {
            // Crear el nodo de clientes
            $nodeCustomers = $this->xml->createElement('Customers');

            foreach ($customers as $customer) {
                $customerName = $this->xml->createElement('name', $this->escapeChars($customer->customer_name));
                $customerDescription = $this->xml->createElement('description', $this->escapeChars($customer->customer_description));

                // Crear el nodo de categoría
                $nodeCustomer = $this->xml->createElement('Customer');
                $nodeCustomer->setAttribute('id', $customer->customer_id);
                $nodeCustomer->appendChild($customerName);
                $nodeCustomer->appendChild($customerDescription);

                // Añadir categoría al nodo de categorías
                $nodeCustomers->appendChild($nodeCustomer);
            }

            $this->appendNode($nodeCustomers);
        } catch (\DOMException $e) {
            throw new SPException(SPException::SP_WARNING, $e->getMessage(), __FUNCTION__);
        }
    }

    /**
     * Crear el nodo con los datos de las cuentas
     *
     * @throws SPException
     */
    private function createAccounts()
    {
        $accounts = AccountUtil::getAccountsData();

        if (count($accounts) === 0) {
            return;
        }

        try {
            // Crear el nodo de cuentas
            $nodeAccounts = $this->xml->createElement('Accounts');

            foreach ($accounts as $account) {
                $accountName = $this->xml->createElement('name', $this->escapeChars($account->account_name));
                $accountCustomerId = $this->xml->createElement('customerId', $account->account_customerId);
                $accountCategoryId = $this->xml->createElement('categoryId', $account->account_categoryId);
                $accountLogin = $this->xml->createElement('login', $this->escapeChars($account->account_login));
                $accountUrl = $this->xml->createElement('url', $this->escapeChars($account->account_url));
                $accountNotes = $this->xml->createElement('notes', $this->escapeChars($account->account_notes));
                $accountPass = $this->xml->createElement('pass', $this->escapeChars(base64_encode($account->account_pass)));
                $accountIV = $this->xml->createElement('passiv', $this->escapeChars(base64_encode($account->account_IV)));

                // Crear el nodo de cuenta
                $nodeAccount = $this->xml->createElement('Account');
                $nodeAccount->setAttribute('id', $account->account_id);
                $nodeAccount->appendChild($accountName);
                $nodeAccount->appendChild($accountCustomerId);
                $nodeAccount->appendChild($accountCategoryId);
                $nodeAccount->appendChild($accountLogin);
                $nodeAccount->appendChild($accountUrl);
                $nodeAccount->appendChild($accountNotes);
                $nodeAccount->appendChild($accountPass);
                $nodeAccount->appendChild($accountIV);

                // Añadir cuenta al nodo de cuentas
                $nodeAccounts->appendChild($nodeAccount);
            }

            $this->appendNode($nodeAccounts);
        } catch (\DOMException $e) {
            throw new SPException(SPException::SP_WARNING, $e->getMessage(), __FUNCTION__);
        }
    }

    /**
     * Crear el hash del archivo XML e insertarlo en el árbol DOM
     */
    private function createHash()
    {
        try {
            if ($this->encrypted === true) {
                $hash = md5($this->getNodeXML('Encrypted'));
            } else {
                $hash = md5($this->getNodeXML('Categories') . $this->getNodeXML('Customers') . $this->getNodeXML('Accounts'));
            }

            $metaHash = $this->xml->createElement('Hash', $hash);

            $nodeMeta = $this->root->getElementsByTagName('Meta')->item(0);
            $nodeMeta->appendChild($metaHash);
        } catch (\DOMException $e) {
            throw new SPException(SPException::SP_WARNING, $e->getMessage(), __FUNCTION__);
        }
    }

    /**
     * Devuelve el código XML de un nodo
     *
     * @param $node string El nodo a devolver
     * @return string
     * @throws SPException
     */
    private function getNodeXML($node)
    {
        try {
            $nodeXML = $this->xml->saveXML($this->root->getElementsByTagName($node)->item(0));
            return $nodeXML;
        } catch (\DOMException $e) {
            throw new SPException(SPException::SP_WARNING, $e->getMessage(), __FUNCTION__);
        }
    }

    /**
     * Generar el archivo XML
     *
     * @return bool
     * @throws SPException
     */
    private function writeXML()
    {
        try {
            $this->xml->formatOutput = true;
            $this->xml->preserveWhiteSpace = false;

            if (!$this->xml->save($this->exportFile)) {
                throw new SPException(SPException::SP_CRITICAL, _('Error al crear el archivo XML'));
            }
        } catch (\DOMException $e) {
            throw new SPException(SPException::SP_WARNING, $e->getMessage(), __FUNCTION__);
        }
    }

    /**
     * Genera el nombre del archivo usado para la exportación.
     */
    private function setExportFile()
    {
        // Generar hash unico para evitar descargas no permitidas
        $exportUniqueHash = uniqid();
        Config::getConfig()->setExportHash($exportUniqueHash);
        Config::saveConfig();

        $this->exportFile = $this->exportDir . DIRECTORY_SEPARATOR . Util::getAppInfo('appname') . '-' . $exportUniqueHash . '.xml';
    }

    /**
     * @param string $exportDir
     */
    public function setExportDir($exportDir)
    {
        $this->exportDir = $exportDir;
    }

    /**
     * Devolver el archivo XML con las cabeceras HTTP
     */
    private function sendFileToBrowser($file)
    {
        // Enviamos el archivo al navegador
        header('Set-Cookie: fileDownload=true; path=/');
        header('Cache-Control: max-age=60, must-revalidate');
        header("Content-length: " . filesize($file));
        Header('Content-type: text/xml');
//        header("Content-type: " . filetype($this->_exportFile));
        header("Content-Disposition: attachment; filename=\"$file\"");
        header("Content-Description: PHP Generated Data");
//        header("Content-transfer-encoding: binary");

        return file_get_contents($file);
    }

    /**
     * Comprobar y crear el directorio de exportación.
     *
     * @throws SPException
     * @return bool
     */
    private function checkExportDir()
    {
        if (!is_dir($this->exportDir)) {
            if (!@mkdir($this->exportDir, 0550)) {
                throw new SPException(SPException::SP_CRITICAL, _('No es posible crear el directorio de backups') . ' (' . $this->exportDir . ')');
            }
        }

        if (!is_writable($this->exportDir)) {
            throw new SPException(SPException::SP_CRITICAL, _('Compruebe los permisos del directorio de backups'));
        }

        return true;
    }

    /**
     * Eliminar los archivos de exportación anteriores
     */
    private function deleteOldExports()
    {
        array_map('unlink', glob($this->exportDir . DIRECTORY_SEPARATOR . '*.xml'));
    }
}