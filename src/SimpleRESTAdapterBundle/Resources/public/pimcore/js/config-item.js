/**
 * Simple REST Adapter.
 *
 * LICENSE
 *
 * This source file is subject to the GNU General Public License version 3 (GPLv3)
 * For the full copyright and license information, please view the LICENSE.md and gpl-3.0.txt
 * files that are distributed with this source code.
 *
 * @copyright  Copyright (c) 2021 CI HUB GmbH (https://ci-hub.com)
 * @license    https://github.com/ci-hub-gmbh/SimpleRESTAdapterBundle/blob/master/gpl-3.0.txt GNU General Public License version 3 (GPLv3)
 */

pimcore.registerNS('pimcore.plugin.simpleRestAdapterBundle.configuration.configItem');
pimcore.plugin.simpleRestAdapterBundle.configuration.configItem = Class.create(pimcore.plugin.datahub.configuration.graphql.configItem, {
  getPanels: function () {
    return [
      this.getGeneral(),
      this.getSchema(),
      this.getWorkspaces(),
      this.getLabelSettings(),
      this.getDeliverySettings(),
      this.getPermissions()
    ];
  },

  initialize: function (data, parent) {
    this.parent = parent;
    this.data = data.configuration;
    this.modificationDate = data.modificationDate;

    this.tab = new Ext.TabPanel({
      activeTab: 0,
      title: this.data.general.name,
      closable: true,
      deferredRender: false,
      forceLayout: true,
      iconCls: `plugin_pimcore_datahub_icon_${this.data.general.type}`,
      id: `plugin_pimcore_datahub_configpanel_panel_${data.name}`,
      buttons: {
        componentCls: 'plugin_pimcore_datahub_statusbar',
        itemId: 'footer',
      },
      items: this.getPanels(),
    });

    this.tab.on('activate', this.tabactivated.bind(this));
    this.tab.on('destroy', this.tabdestroy.bind(this));
    this.setupChangeDetector();

    this.parent.configPanel.editPanel.add(this.tab);
    this.parent.configPanel.editPanel.setActiveTab(this.tab);
    this.parent.configPanel.editPanel.updateLayout();

    this.showInfo();
  },

  showInfo: function () {
    const footer = this.tab.getDockedComponent('footer');

    footer.removeAll();
    footer.add('->');

    footer.add({
      text: t('save'),
      iconCls: 'pimcore_icon_apply',
      handler: this.save.bind(this),
    });
  },

  updateLabelList: function (doCleanup) {
    if (this.labelListMightHaveChanged || doCleanup) {
      Ext.Ajax.request({
        url: Routing.generate('simple_rest_adapter_config_label_list'),
        params: {
          name: this.data.general.name,
        },
        success: (response) => {
          const rdata = Ext.decode(response.responseText);

          if (rdata && rdata.success) {
            if (doCleanup) {
              const labelRecords = this.labelStore.queryBy(() => true);
              labelRecords.items.forEach((record) => {
                if (!rdata.labelList.includes(record.data.id)) {
                  this.labelStore.remove(record);
                }
              });
            }

            rdata.labelList.forEach((label) => {
              if (!this.labelStore.findRecord('id', label)) {
                this.labelStore.add({ 'id': label });
              }
            });
            this.labelListMightHaveChanged = false;
          } else {
            pimcore.helpers.showNotification(
                t('error'),
                t('plugin_pimcore_datahub_configpanel_update_labels_error'),
                'error',
                t(rdata.message)
            );
          }
        },
      });
    }
  },

  tabdestroy: function () {
    this.tabdestroyed = true;
  },

  save: function () {
    const saveData = this.getSaveData();

    Ext.Ajax.request({
      url: Routing.generate('simple_rest_adapter_config_save'),
      params: {
        data: saveData,
        modificationDate: this.modificationDate,
      },
      method: 'post',
      success: (response) => {
        const rdata = Ext.decode(response.responseText);

        if (rdata && rdata.success) {
          this.modificationDate = rdata.modificationDate;
          this.labelListMightHaveChanged = true;
          this.resetChanges();
          pimcore.helpers.showNotification(
              t('success'),
              t('plugin_pimcore_datahub_configpanel_item_save_success'),
              'success'
          );
        } else {
          pimcore.helpers.showNotification(
              t('error'),
              t('plugin_pimcore_datahub_configpanel_item_saveerror'),
              'error',
              t(rdata.message)
          );
        }
      },
    });
  },

  getGeneral: function () {
    this.generalForm = new Ext.form.FormPanel({
      bodyStyle: 'padding:10px;',
      autoScroll: true,
      defaults: {
        labelWidth: 200,
        width: 600,
      },
      border: false,
      title: t('plugin_pimcore_datahub_configpanel_item_general'),
      items: [
        {
          xtype: 'checkbox',
          fieldLabel: t('active'),
          name: 'active',
          value: this.data.general && this.data.general.hasOwnProperty('active')
              ? this.data.general.active
              : true,
        },
        {
          xtype: 'textfield',
          fieldLabel: t('type'),
          name: 'type',
          value: t(`plugin_pimcore_datahub_type_${this.data.general.type}`),
          readOnly: true,
        },
        {
          xtype: 'textfield',
          fieldLabel: t('name'),
          name: 'name',
          value: this.data.general.name,
          readOnly: true,
        },
        {
          name: 'description',
          fieldLabel: t('description'),
          xtype: 'textarea',
          height: 100,
          value: this.data.general.description,
        },
      ],
    });

    return this.generalForm;
  },

  getSchema: function () {
    const schemaGrid = this.createSchemaStoreAndGrid('query');

    const thumbnailStore = new Ext.data.JsonStore({
      autoDestroy: true,
      autoLoad: true,
      proxy: {
        type: 'ajax',
        url: Routing.generate('simple_rest_adapter_config_thumbnails'),
        reader: {
          rootProperty: 'data',
          idProperty: 'name',
        },
      },
      fields: ['name'],
    });

    this.schemaForm = new Ext.form.FormPanel({
      bodyStyle: 'padding:10px;',
      autoScroll: true,
      defaults: {
        labelWidth: 200,
        width: 800,
      },
      border: false,
      title: t('plugin_pimcore_datahub_configpanel_schema'),
      items: [
        {
          xtype: 'fieldset',
          title: t('plugin_pimcore_datahub_simple_rest_data_object_classes'),
          items: [schemaGrid],
        },
        {
          xtype: 'fieldset',
          title: t('plugin_pimcore_datahub_simple_rest_assets'),
          items: [
            {
              xtype: 'checkbox',
              labelWidth: 200,
              fieldLabel: t('plugin_pimcore_datahub_simple_rest_assets_enabled'),
              name: 'enabled',
              value: this.data.schema && this.data.schema.hasOwnProperty('assets')
                  ? this.data.schema.assets.enabled
                  : false,
            },
            {
              xtype: 'checkbox',
              labelWidth: 200,
              fieldLabel: t('plugin_pimcore_datahub_simple_rest_assets_allow_original_image'),
              name: 'allowOriginalImage',
              value: this.data.schema && this.data.schema.hasOwnProperty('assets')
                  ? this.data.schema.assets.allowOriginalImage
                  : false,
            },
            {
              xtype: 'tagfield',
              labelWidth: 200,
              fieldLabel: t('plugin_pimcore_datahub_simple_rest_assets_thumbnails'),
              name: 'thumbnails',
              width: 768,
              store: thumbnailStore,
              valueField: 'name',
              displayField: 'name',
              value: this.data.schema && this.data.schema.hasOwnProperty('assets')
                  ? this.data.schema.assets.thumbnails
                  : [],
            },
          ],
        },
      ],
    });

    return this.schemaForm;
  },

  createSchemaStoreAndGrid: function () {
    const schemaToolbar = Ext.create('Ext.Toolbar', {
      cls: 'main-toolbar',
      items: [
        {
          text: t('add'),
          handler: this.onAdd.bind(this, 'dataObject'),
          iconCls: 'pimcore_icon_add',
        },
      ],
    });

    const fields = ['id', 'language', 'columnConfig'];
    this.dataObjectSchemaStore = Ext.create('Ext.data.Store', {
      reader: {
        type: 'memory',
      },
      fields: fields,
      data: this.data.schema ? this.data.schema.dataObjectClasses : [],
    });

    const columns = [
      {
        text: t('plugin_pimcore_datahub_configpanel_entity'),
        sortable: true,
        dataIndex: 'name',
        editable: false,
        filter: 'string',
        flex: 1,
      },
      {
        xtype: 'actioncolumn',
        text: t('settings'),
        menuText: t('settings'),
        width: 60,
        items: [{
          tooltip: t('settings'),
          icon: '/bundles/pimcoreadmin/img/flat-color-icons/settings.svg',
          handler: (grid, rowIndex) => {
            const record = grid.getStore().getAt(rowIndex);
            const classStore = pimcore.globalmanager.get('object_types_store');
            const classIdx = classStore.findExact('text', record.data.id);

            if (classIdx >= 0) {
              let classRecord = classStore.getAt(classIdx);
              let classId = classRecord.data.id;
              let columnConfig = record.get('columnConfig') || [];
              let language = record.get('language') || 'en';

              this.openSchemaDialog(classId, columnConfig, language, record);
            }
          },
        }],
      },
      {
        xtype: 'actioncolumn',
        text: t('delete'),
        menuText: t('delete'),
        width: 60,
        items: [
          {
            tooltip: t('delete'),
            icon: '/bundles/pimcoreadmin/img/flat-color-icons/delete.svg',
            handler: (grid, rowIndex) => {
              grid.getStore().removeAt(rowIndex);
            },
          },
        ],
      },
    ];

    this.dataObjectSchemaGrid = Ext.create('Ext.grid.Panel', {
      frame: false,
      bodyCls: 'pimcore_editable_grid',
      autoScroll: true,
      store: this.dataObjectSchemaStore,
      columnLines: true,
      stripeRows: true,
      columns: {
        items: columns,
      },
      trackMouseOver: true,
      selModel: Ext.create('Ext.selection.RowModel', {}),
      tbar: schemaToolbar,
      viewConfig: {
        forceFit: true,
        enableTextSelection: true,
      },
    });

    return this.dataObjectSchemaGrid;
  },

  openSchemaDialog: function (classId, columnConfig, language, record) {
    const objectId = 1;

    const dialogColumnConfig = {
      classid: classId,
      language: language,
    };

    const fieldKeys = Object.keys(columnConfig);
    const selectedGridColumns = [];

    for (let i = 0; i < fieldKeys.length; i++) {
      const field = columnConfig[fieldKeys[i]];

      if (!field.hidden) {
        const fc = {
          key: fieldKeys[i],
          label: field.fieldConfig.label,
          dataType: field.fieldConfig.type,
          layout: field.fieldConfig.layout,
        };

        if (field.fieldConfig.width) {
          fc.width = field.fieldConfig.width;
        }

        if (field.fieldConfig.locked) {
          fc.locked = field.fieldConfig.locked;
        }

        if (field.isOperator) {
          fc.isOperator = true;
          fc.attributes = field.fieldConfig.attributes;
        }

        selectedGridColumns.push(fc);
      }
    }

    dialogColumnConfig.selectedGridColumns = selectedGridColumns;

    const settings = {
      source: 'pimcore_data_hub_simple_rest',
    };

    const gridConfigDialog = new pimcore.plugin.simpleRestAdapterBundle.configuration.gridConfigDialog(
        dialogColumnConfig,
        function (data, settings, save, context) {
          const columns = {};

          // convert to data array as grid uses it
          for (let i = 0; i < data.columns.length; i++) {
            let curr = data.columns[i];
            columns[curr.key] = {
              name: curr.key,
              position: (i + 1),
              hidden: false,
              fieldConfig: curr,
              isOperator: curr.isOperator,
            };
          }

          record.set('columnConfig', columns);
          record.set('language', data.language);
        },
        () => {
          gridConfigDialog.window.close();
        },
        false,
        settings,
        {
          allowPreview: true,
          classId: classId,
          objectId: objectId,
        },
    );
    gridConfigDialog.itemsPerPage.hide();
  },

  getWorkspaces: function () {
    this.assetWorkspace = new pimcore.plugin.datahub.workspace.asset(this);
    this.assetWorkspace.availableRights = ['read'];

    this.objectWorkspace = new pimcore.plugin.datahub.workspace.object(this);
    this.objectWorkspace.availableRights = ['read'];

    return new Ext.form.FormPanel({
      bodyStyle: 'padding:10px;',
      autoScroll: true,
      defaults: {
        labelWidth: 200,
      },
      border: false,
      title: t('plugin_pimcore_datahub_simple_rest_configpanel_workspaces'),
      items: [
        {
          xtype: 'fieldset',
          width: 800,
          title: t('workspaces'),
          items: [
            this.assetWorkspace.getPanel(),
            this.objectWorkspace.getPanel(),
          ],
        },
      ],
    });
  },

  getLabelSettings: function () {
    const languages = pimcore.settings.websiteLanguages;
    const columns = [
      {
        text: t('plugin_pimcore_datahub_simple_rest_configpanel_key'),
        flex: 200,
        sortable: true,
        dataIndex: 'id',
      },
    ];
    const storeFields = ['id', 'useInAggs'];

    languages.forEach((language) => {
      columns.push({
        cls: `x-column-header_${language}`,
        text: pimcore.available_languages[language],
        sortable: true,
        flex: 200,
        dataIndex: language,
        editor: new Ext.form.TextField({}),
        renderer: (text) => {
          if (text) {
            return replace_html_event_attributes(
                strip_tags(text, 'div,span,b,strong,em,i,small,sup,sub,p')
            );
          }
        },
      });
      storeFields.push(language);
    });
    columns.push(new Ext.grid.column.Check({
      text: t('plugin_pimcore_datahub_simple_rest_configpanel_useInAggs'),
      dataIndex: 'useInAggs',
      width: 50,
    }));

    const cellEditing = Ext.create('Ext.grid.plugin.CellEditing', {
      clicksToEdit: 1,
    });

    this.labelStore = Ext.create('Ext.data.JsonStore', {
      data: this.data.labelSettings,
      fields: storeFields,
    });

    const grid = Ext.create('Ext.grid.Panel', {
      autoScroll: true,
      store: this.labelStore,
      columns: {
        items: columns,
        defaults: {
          renderer: Ext.util.Format.htmlEncode,
        },
      },
      selModel: Ext.create('Ext.selection.RowModel', {}),
      plugins: [
        cellEditing,
      ],
      tbar: {
        items: [
          '->',
          {
            xtype: 'button',
            text: t('plugin_pimcore_datahub_simple_rest_configpanel_label_cleanup'),
            handler: this.updateLabelList.bind(this, true),
          },
        ],
      },
      trackMouseOver: true,
      columnLines: true,
      bodyCls: 'pimcore_editable_grid',
      stripeRows: true,
      viewConfig: {
        forceFit: true,
        markDirty: false,
      },
    });

    return new Ext.form.FormPanel({
      bodyStyle: 'padding:10px;',
      autoScroll: true,
      defaults: {
        labelWidth: 200,
      },
      border: false,
      title: t('plugin_pimcore_datahub_simple_rest_configpanel_label_settings'),
      items: [
        {
          xtype: 'fieldset',
          width: '100%',
          title: t('plugin_pimcore_datahub_simple_rest_configpanel_labels'),
          items: [
            {
              xtype: 'displayfield',
              hideLabel: false,
              value: t('plugin_pimcore_datahub_simple_rest_configpanel_label_settings_description'),
              readOnly: true,
              disabled: true,
            },
            grid,
          ],
        },
      ],
    });
  },

  getDeliverySettings: function () {
    const apikeyField = new Ext.form.field.Text({
      xtype: 'textfield',
      labelWidth: 200,
      width: 600,
      fieldLabel: t('plugin_pimcore_datahub_security_datahub_apikey'),
      name: 'apikey',
      value: this.data.deliverySettings ? this.data.deliverySettings.apikey : '',
      minLength: 16,
    });

    this.deliverySettingsForm = new Ext.form.FormPanel({
      bodyStyle: 'padding:10px;',
      autoScroll: true,
      defaults: {
        labelWidth: 200,
      },
      border: false,
      title: t('plugin_pimcore_datahub_simple_rest_configpanel_delivery_settings'),
      items: [
        {
          xtype: 'fieldcontainer',
          layout: 'hbox',
          items: [
            apikeyField,
            {
              xtype: 'button',
              width: 32,
              style: 'margin-left: 8px',
              iconCls: 'pimcore_icon_clear_cache',
              handler: () => {
                apikeyField.setValue(md5(uniqid()));
              },
            },
          ],
        },
        {
          xtype: 'displayfield',
          hideLabel: false,
          value: t('plugin_pimcore_datahub_simple_rest_security_apikey_description'),
          cls: 'pimcore_extra_label_bottom',
          readOnly: true,
          disabled: true,
        },
        {
          xtype: 'displayfield',
          hideLabel: false,
          value: this.data.swaggerUrl,
          fieldLabel: t('plugin_pimcore_datahub_simple_rest_devliery_swagger_url'),
          readOnly: false,
          disabled: false,
        },
        {
          xtype: 'displayfield',
          hideLabel: false,
          value: this.data.treeItemsUrl,
          fieldLabel: t('plugin_pimcore_datahub_simple_rest_devliery_tree_items_url'),
          readOnly: false,
          disabled: false,
        },
        {
          xtype: 'displayfield',
          hideLabel: false,
          value: this.data.searchUrl,
          fieldLabel: t('plugin_pimcore_datahub_simple_rest_devliery_search_url'),
          readOnly: false,
          disabled: false,
        },
        {
          xtype: 'displayfield',
          hideLabel: false,
          value: this.data.getElementByIdUrl,
          fieldLabel: t('plugin_pimcore_datahub_simple_rest_devliery_get_element_by_id_url'),
          readOnly: false,
          disabled: false,
        },
      ],
    });

    return this.deliverySettingsForm;
  },

  filterIds: function (dataArray) {
    for (let i = 0; i < dataArray.length; i++) {
      const currentData = dataArray[i];
      delete currentData.id;
    }

    return dataArray;
  },

  getSaveData: function () {
    const saveData = {};
    saveData['general'] = this.generalForm.getForm().getValues();
    saveData['deliverySettings'] = this.deliverySettingsForm.getForm().getValues();
    saveData['schema'] = {};
    saveData['schema']['assets'] = this.schemaForm.getForm().getValues();
    saveData['schema']['dataObjectClasses'] = this.getSchemaData('dataObject');
    saveData['workspaces'] = {};
    saveData['workspaces']['asset'] = this.filterIds(this.assetWorkspace.getValues());
    saveData['workspaces']['object'] = this.filterIds(this.objectWorkspace.getValues());
    saveData["permissions"] = this.getPermissionsSaveData();

    const labelData = [];
    const labelRecords = this.labelStore.getData();
    labelRecords.items.forEach(record => labelData.push(record.data));
    saveData['labelSettings'] = labelData;

    return Ext.encode(saveData);
  },
});
