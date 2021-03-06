<?php

/**
 * @package   	JCE
 * @copyright 	Copyright (c) 2009-2012 Ryan Demmer. All rights reserved.
 * @license   	GNU/GPL 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * JCE is free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 */
defined('_JEXEC') or die('RESTRICTED');

jimport('joomla.installer.installer');
jimport('joomla.installer.helper');

// load base model
require_once(dirname(__FILE__) . '/model.php');

class WFModelInstaller extends WFModel {

    /** @var object JTable object */
    var $_table = null;

    /** @var object JTable object */
    var $_url = null;
    var $_result = array();

    public function cancel() {
        $this->setRedirect(JRoute::_('index.php?option=com_jce&client=' . $client, false));
    }

    /**
     * Get a JCE installer adapter
     * @param string $name adapter name eg: plugin.
     * @return $adapter instance
     */
    public function getAdapter($name) {
        // get installer instance
        $installer = JInstaller::getInstance();

        // Try to load the adapter object
        require_once(JPATH_COMPONENT . '/adapters/' . strtolower($name) . '.php');

        $class = 'WFInstaller' . ucfirst($name);

        if (!class_exists($class)) {
            return false;
        }

        $adapter = new $class($installer);
        $adapter->parent = $installer;

        return $adapter;
    }

    public function install($package = null) {
        $mainframe = JFactory::getApplication();

        if (!$package) {
            $package = $this->getPackage();
        }

        // Was the package unpacked?
        if (!$package) {
            $this->setState('message', 'WF_INSTALLER_NO_PACKAGE');
            return false;
        }

        // Get an installer instance
        $installer = JInstaller::getInstance();

        // Set Adapter
        $type = $package['type'];

        if (!$type) {
            $this->setState('message', 'WF_INSTALLER_NO_PACKAGE');
            return false;
        }

        $adapter = $this->getAdapter($type);
        $installer->setAdapter($type, $adapter);

        // Install the package
        if (!$installer->install($package['dir'])) {
            $result = false;
        } else {
            $result = true;
        }

        $this->_result[] = array(
            'name' => $installer->get('name'),
            'type' => $type,
            'version' => $installer->get('version'),
            'result' => $result,
            'message' => $installer->get('message'),
            'extension.message' => $installer->get('extension.message')
        );

        $this->setState('install.result', $this->_result);

        // Cleanup the install files
        if (!is_file($package['packagefile'])) {
            $config = JFactory::getConfig();
            $package['packagefile'] = $config->getValue('config.tmp_path') . '/' . $package['packagefile'];
        }
        if (is_file($package['packagefile'])) {
            JInstallerHelper::cleanupInstall($package['packagefile'], $package['extractdir']);
        }
        return $result;
    }

    public function remove($id, $type) {
        $mainframe = JFactory::getApplication();

        $installer = JInstaller::getInstance();

        // Use Joomla! Installer class for related extensions
        if ($type == 'related') {
            $table = WF_JOOMLA15 ? 'plugin' : 'extension';

            $row = JTable::getInstance($table);
            // get extension data not returned by uninstall method
            $row->load($id);
            // get manifest
            $manifest = WF_JOOMLA15 ? JPATH_PLUGINS . $row->folder . '/' . $row->element . '.xml' : JPATH_PLUGINS . '/' . $row->folder . '/' . $row->element . '/' . $row->element . '.xml';

            if (file_exists($manifest)) {
                $xml = WFXMLHelper::parseInstallManifest($manifest);

                if ($xml) {
                    $installer->set('name', $xml['name']);
                    $installer->set('version', $xml['version']);
                }
            }

            $result = $installer->uninstall('plugin', $id);
        } else {
            // Set Adapter
            $adapter = $this->getAdapter($type);
            $installer->setAdapter($type, $adapter);
            $result = $installer->uninstall($type, $id);
        }

        $result = $result ? true : false;

        $this->_result[] = array(
            'name' => $installer->get('name'),
            'type' => $type,
            'version' => $installer->get('version'),
            'result' => $result
        );

        $this->setState('install.result', $this->_result);

        return $result;
    }

    /**
     * Get the install package or folder
     * @return Array $package
     */
    private function getPackage() {
        $config = JFactory::getConfig();
        jimport('joomla.filesystem.file');

        // set standard method
        $upload = true;
        $package = null;

        // Get the uploaded file information
        $file = JRequest::getVar('install', null, 'files', 'array');
        // get the file path information
        $path = JRequest::getString('install_input');

        if (!(bool) ini_get('file_uploads') || !is_array($file)) {
            $upload = false;
            // no path either!
            if (!$path) {
                JError::raiseWarning('SOME_ERROR_CODE', WFText::_('WARNINSTALLFILE'));
                return false;
            }
        }

        // Install failed
        if ((!$file['tmp_name'] && !$file['name']) || ($file['error'] || $file['size'] < 1)) {
            $upload = false;
            // no path either!
            if (!$path) {
                JError::raiseWarning('SOME_ERROR_CODE', WFText::_('WF_INSTALLER_NO_FILE'));
                return false;
            }
        }

        // uploaded file
        if ($upload && $file['tmp_name'] && $file['name']) {
            // check extension
            if (!preg_match('/\.(zip|tar|gz|gzip|tgz|tbz2|bz2|bzip2)$/i', $file['name'])) {
                JError::raiseWarning('SOME_ERROR_CODE', WFText::_('WARNINSTALLFILE'));
                return false;
            }

            $dest = $config->getValue('config.tmp_path') . '/' . $file['name'];
            $src = $file['tmp_name'];
            // upload file
            JFile::upload($src, $dest);
            // path to file
        } else {
            $dest = JPath::clean($path);
        }

        // Unpack the package file
        if (preg_match('/\.(zip|tar|gz|gzip|tgz|tbz2|bz2|bzip2)/i', $dest)) {
            // Make sure that zlib is loaded so that the package can be unpacked
            if (!extension_loaded('zlib')) {
                JError::raiseWarning('SOME_ERROR_CODE', WFText::_('WARNINSTALLZLIB'));
                return false;
            }
            $package = JInstallerHelper::unpack($dest);
            // might be a directory
        } else {
            if (!is_dir($dest)) {
                JError::raiseWarning('SOME_ERROR_CODE', WFText::_('WF_INSTALLER_INVALID_SRC'));
                return false;
            }

            // Detect the package type
            $type = @JInstallerHelper::detectType($dest);

            $package = array(
                'packagefile' => null,
                'extractdir' => null,
                'dir' => $dest,
                'type' => $type
            );
        }

        $package['manifest'] = null;

        // set install method
        JRequest::setVar('install_method', 'install');

        return $package;
    }

    public function getExtensions() {
        $db = JFactory::getDBO();

        $model = JModel::getInstance('plugins', 'WFModel');

        // get an array of all installed plugins in plugins folder
        $extensions = $model->getExtensions();

        return $extensions;
    }

    public function getPlugins() {
        $model = JModel::getInstance('plugins', 'WFModel');

        // get an array of all installed plugins in plugins folder
        $plugins = $model->getPlugins();

        $rows = array();

        $language = JFactory::getLanguage();

        foreach ($plugins as $plugin) {
            if ($plugin->core == 0) {
                $rows[] = $plugin;
                $language->load('com_jce_' . trim($plugin->name), JPATH_SITE);
            }
        }

        return $rows;
    }

    /**
     * Get additional plugins such as JCE MediaBox etc.
     * @return 
     */
    public function getRelated() {
        // Get a database connector
        $db = JFactory::getDBO();

        $params = JComponentHelper::getParams('com_jce');

        // pre-defined array of other plugins
        $related = explode(',', $params->get('related_extensions', 'jcemediabox,jceutilities,mediaobject,wfmediabox'));

        $query  = $db->getQuery(true);

        // Joomla! 2.5
        if (is_object($query)) {
            $query->select(array('name', 'element', 'folder'))->from('#__extensions')->where('type = ' . $db->Quote('plugin'))->order('name');
            // Joomla! 1.5    
        } else {
            $query = 'SELECT name, element, folder FROM #__plugins ORDER BY name';
        }

        $db->setQuery($query);
        $rows = $db->loadObjectList() or die($db->stdErr());

        $language = JFactory::getLanguage();

        $numRows = count($rows);
        for ($i = 0; $i < $numRows; $i++) {
            $row = $rows[$i];

            if (in_array($row->element, $related) === false) {
                unset($rows[$i]);
            }

            $file = JPATH_PLUGINS . '/' . $row->folder . '/' . $row->element . '/' . $row->element . ".xml";

            if (WF_JOOMLA15) {
                $file = JPATH_PLUGINS . '/' . $row->folder . '/' . $row->element . ".xml";
            }

            if (is_file($file)) {
                $xml = WFXMLElement::getXML($file);

                if ($xml) {
                    $row->title = (string) $xml->name;

                    $row->author = (string) $xml->author;
                    $row->version = (string) $xml->version;
                    $row->creationdate = (string) $xml->creationDate;
                    $row->description = (string) $xml->description;
                    $row->authorUrl = (string) $xml->authorUrl;
                }
            }

            $language->load('plg_' . trim($row->folder) . '_' . trim($row->element), JPATH_ADMINISTRATOR);
            $language->load('plg_' . trim($row->folder) . '_' . trim($row->element), JPATH_SITE);
        }

        return array_values($rows);
    }

    public function getLanguages() {
        // Get the site languages
        $base = JLanguage::getLanguagePath(JPATH_SITE);
        $dirs = JFolder::folders($base);

        for ($i = 0; $i < count($dirs); $i++) {
            $lang = new stdClass();
            $lang->folder = $dirs[$i];
            $lang->baseDir = $base;
            $languages[] = $lang;
        }
        $rows = array();
        foreach ($languages as $language) {
            $files = JFolder::files($language->baseDir . '/' . $language->folder, '\.(com_jce)\.xml$');
            foreach ($files as $file) {
                $data = WFXMLHelper::parseInstallManifest($language->baseDir . '/' . $language->folder . '/' . $file);

                $row = new StdClass();
                $row->language = $language->folder;

                if ($row->language == 'en-GB') {
                    $row->cbd = 'disabled="disabled"';
                    $row->style = ' style="color:#999999;"';
                } else {
                    $row->cbd = '';
                    $row->style = '';
                }

                // If we didn't get valid data from the xml file, move on...
                if (!is_array($data)) {
                    continue;
                }

                // Populate the row from the xml meta file
                foreach ($data as $key => $value) {
                    $row->$key = $value;
                }
                $rows[] = $row;
            }
        }

        return $rows;
    }

}