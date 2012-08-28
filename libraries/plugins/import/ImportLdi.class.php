<?php
/* vim: set expandtab sw=4 ts=4 sts=4: */
/**
 * CSV import plugin for phpMyAdmin using LOAD DATA
 *
 * @package    PhpMyAdmin-Import
 * @subpackage LDI
 */
if (! defined('PHPMYADMIN')) {
    exit;
}

/* Get the import interface */
require_once "libraries/plugins/ImportPlugin.class.php";

// We need relations enabled and we work only on database
if ($GLOBALS['plugin_param'] !== 'table') {
    $GLOBALS['skip_import'] = true;
    return;
}

/**
 * Handles the import for the CSV format using load data
 *
 * @package PhpMyAdmin-Import
 */
class ImportLdi extends ImportPlugin
{
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->setProperties();
    }

    /**
     * Sets the import plugin properties.
     * Called in the constructor.
     *
     * @return void
     */
    protected function setProperties()
    {
        if ($GLOBALS['cfg']['Import']['ldi_local_option'] == 'auto') {
            $GLOBALS['cfg']['Import']['ldi_local_option'] = false;

            $result = PMA_DBI_try_query('SHOW VARIABLES LIKE \'local\\_infile\';');
            if ($result != false && PMA_DBI_num_rows($result) > 0) {
                $tmp = PMA_DBI_fetch_row($result);
                if ($tmp[1] == 'ON') {
                    $GLOBALS['cfg']['Import']['ldi_local_option'] = true;
                }
            }
            PMA_DBI_free_result($result);
            unset($result);
        }

        $props = 'libraries/properties/';
        include_once "$props/plugins/ImportPluginProperties.class.php";
        include_once "$props/options/groups/OptionsPropertyRootGroup.class.php";
        include_once "$props/options/groups/OptionsPropertyMainGroup.class.php";
        include_once "$props/options/items/BoolPropertyItem.class.php";
        include_once "$props/options/items/TextPropertyItem.class.php";

        $importPluginProperties = new ImportPluginProperties();
        $importPluginProperties->setText('CSV using LOAD DATA');
        $importPluginProperties->setExtension('ldi');
        $importPluginProperties->setOptionsText(__('Options'));

        // create the root group that will be the options field for
        // $importPluginProperties
        // this will be shown as "Format specific options"
        $importSpecificOptions = new OptionsPropertyRootGroup();
        $importSpecificOptions->setName("Format Specific Options");

        // general options main group
        $generalOptions = new OptionsPropertyMainGroup();
        $generalOptions->setName("general_opts");
        // create primary items and add them to the group
        $leaf = new BoolPropertyItem();
        $leaf->setName("replace");
        $leaf->setText(__('Replace table data with file'));
        $generalOptions->addProperty($leaf);

        // add the main group to the root group
        $importSpecificOptions->addProperty($generalOptions);

        // set the options for the import plugin property item
        $importPluginProperties->setOptions($importSpecificOptions);
        $this->properties = $importPluginProperties;
    }

    /**
     * This method is called when any PluginManager to which the observer
     * is attached calls PluginManager::notify()
     *
     * @param SplSubject $subject The PluginManager notifying the observer
     *                            of an update.
     *
     * @return void
     */
    public function update (SplSubject $subject)
    {
    }

    /**
     * Handles the whole import logic
     *
     * @return void
     */
    public function doImport()
    {
        global $finished, $error, $import_file, $compression, $charset_conversion;
        global $ldi_local_option, $ldi_replace, $ldi_terminated, $ldi_enclosed,
            $ldi_escaped, $ldi_new_line, $skip_queries, $ldi_columns;

        $common_functions = PMA_CommonFunctions::getInstance();

        if ($import_file == 'none'
            || $compression != 'none'
            || $charset_conversion
        ) {
            // We handle only some kind of data!
            $message = PMA_Message::error(
                __('This plugin does not support compressed imports!')
            );
            $error = true;
            return;
        }

        $sql = 'LOAD DATA';
        if (isset($ldi_local_option)) {
            $sql .= ' LOCAL';
        }
        $sql .= ' INFILE \'' . $common_functions->sqlAddSlashes($import_file) . '\'';
        if (isset($ldi_replace)) {
            $sql .= ' REPLACE';
        } elseif (isset($ldi_ignore)) {
            $sql .= ' IGNORE';
        }
        $sql .= ' INTO TABLE ' . $common_functions->backquote($table);

        if (strlen($ldi_terminated) > 0) {
            $sql .= ' FIELDS TERMINATED BY \'' . $ldi_terminated . '\'';
        }
        if (strlen($ldi_enclosed) > 0) {
            $sql .= ' ENCLOSED BY \''
                . $common_functions->sqlAddSlashes($ldi_enclosed) . '\'';
        }
        if (strlen($ldi_escaped) > 0) {
            $sql .= ' ESCAPED BY \''
                . $common_functions->sqlAddSlashes($ldi_escaped) . '\'';
        }
        if (strlen($ldi_new_line) > 0) {
            if ($ldi_new_line == 'auto') {
                $ldi_new_line
                    = (PMA_CommonFunctions::getInstance()->whichCrlf() == "\n")
                        ? '\n'
                        : '\r\n';
            }
            $sql .= ' LINES TERMINATED BY \'' . $ldi_new_line . '\'';
        }
        if ($skip_queries > 0) {
            $sql .= ' IGNORE ' . $skip_queries . ' LINES';
            $skip_queries = 0;
        }
        if (strlen($ldi_columns) > 0) {
            $sql .= ' (';
            $tmp   = preg_split('/,( ?)/', $ldi_columns);
            $cnt_tmp = count($tmp);
            for ($i = 0; $i < $cnt_tmp; $i++) {
                if ($i > 0) {
                    $sql .= ', ';
                }
                /* Trim also `, if user already included backquoted fields */
                $sql .= $common_functions->backquote(
                    trim($tmp[$i], " \t\r\n\0\x0B`")
                );
            } // end for
            $sql .= ')';
        }

        PMA_importRunQuery($sql, $sql);
        PMA_importRunQuery();
        $finished = true;
    }
}