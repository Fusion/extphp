<html>
<header>
<link rel="stylesheet" type="text/css" href="views/assets/extjs/css/ext-all.css" />
<script type="text/javascript" src="libs/ext-base.js"></script>
<script type="text/javascript" src="libs/ext-all-debug.js"></script>
<style>
#actions li {
	margin:.3em;
}
#actions li a {
	color:#666688;
	text-decoration:none;
}
</style>
</header>
</body>

<script type="text/javascript">
<?php
function __autoload($class_name) {
        // CamelCase => camel_case
	if(0 == strncmp($class_name, 'Ext_', 4))
	{
		$name = str_replace('_Config', '', $class_name);
		$name = str_replace('_', '.', $name);
		include_once("libs/extphp/$name.php");
	}
}

require 'libs/extphp/main.php';

if(isset($_GET['content1']))
{
	print "Welcome to a remotely loaded page.";
	exit;
}

// **************************** First, a poor lonely button
$cfg = new Ext_Button_Config();
$button = new Ext_Button(
	$cfg->
		renderTo('button1-div')->
		text('Button 1')->
		handler(
			new JsFunction(null, "alert('You clicked the button');")
		)
);
$button->jsrender();

// **************************** A grid panel
// Data Display
$cm = array(
		array('header' => '',         'width' => 30,  'sortable' => false, 'dataIndex' => 'status',		'id' => 'status'),
		array('header' => 'Name',     'width' => 160, 'sortable' => false, 'dataIndex' => 'report',		'id' => 'report'),
		array('header' => 'Duration', 'width' => 70,  'sortable' => false, 'dataIndex' => 'duration',	'id' => 'duration')
);

// Reader
$jsonreader_config = new Ext_data_JsonReader_Config();
$jsonreader_config->root('rows')->totalProperty('totalCount');
// Store Config
$dscfg = new Ext_Data_Store_Config();
$dscfg->
	autoLoad(true)->
	proxy(
		new Ext_data_HttpProxy(array('url'=>'docs/samples/data.txt','method'=>'GET'))
	)->
	reader(
		new Ext_data_JsonReader(
			$jsonreader_config,
			array(
				array('name' => 'status', 'mapping' => 'status', 'type' => 'string'),
				array('name' => 'report', 'mapping' => 'report', 'type' => 'string'),
				array('name' => 'duration', 'mapping' => 'duration', 'type' => 'int')
			)
		)
	);
// Store
$ds = new Ext_data_Store($dscfg);
// And finally the grid panel and its config class themselves:
$cfg = new Ext_grid_GridPanel_Config();
$gp = new Ext_grid_GridPanel(
	$cfg->
		renderTo('grid1-div')->
		title('My Grid')->
		width(320)->
		height(200)->
		frame(true)->
		columns($cm)->
		store($ds)
);
$gp->jsrender();

// **************************** Even more fun: a login window!
$formpanelcfg = new Ext_form_FormPanel_Config();
$loginitems = array(
	array('fieldLabel'=>'Username','name'=>'loginUsername','allowBlank'=>false),
	array('fieldLabel'=>'Password','name'=>'loginPassword','inputType'=>'password','allowBlank'=>false)
);
$loginbuttons = array(
	array('text'=>'Login','formBind'=>true)
);
$login = new Ext_form_FormPanel(
	$formpanelcfg->
		labelWidth(80)->
		frame(true)->
		title('Please Login')->
		width(230)->
		defaultType('textfield')->
		monitorValid(true)->
		buttons($loginbuttons)->
		items($loginitems));
$wincfg = new Ext_Window_Config();
$jswin = new Ext_Window(
	$wincfg->
		layout('fit')->
		width(300)->
		height(150)->
		closable(false)->
		resizable(false)->
		plain(true)->
		items($login)
);
$win = new JsVariable('win', $jswin);
$win->show();

$cfg = new Ext_Panel_Config();
$tabactions = new Ext_Panel(
	$cfg->
		frame(true)->
		title('Actions')->
		collapsible(true)->
		contentEl('actions')->
		titleCollapse(true)
);
$cfg = new Ext_Panel_Config();
$actionpanel = new Ext_Panel(
	$cfg->
		id('action-panel')->
		collapsible(true)->
		width(340)->
		border(false)->
		baseCls('x-plain')->
		items($tabactions)->
		associate('region', 'west')->
		associate('split', true)->
		associate('collapseMode', 'mini')->
		associate('minWidth', 150)
);

$cfg = new Ext_TabPanel_Config();
$jstabpanel = new Ext_TabPanel(
	$cfg->
		deferredRender(false)->
		autoScroll(true)->
		activeTab(0)->
		items(
			array(
				array('id'=>'tab1','contentEl'=>'tabs','title'=>'Button','closable'=>false,'autoScroll'=>true),
				array('id'=>'tab2','contentEl'=>'tabs','title'=>'Grid Panel',  'closable'=>false,'autoScroll'=>true)
			)
		)->
		associate('region', 'center')->
		associate('margins', '0 4 4 0')->
		associate('title', 'Main')->
		associate('closable', false)
);
$tabpanel = new JsVariable('tabpanel', $jstabpanel);

$cfg = new Ext_Viewport_Config();
$viewport = new Ext_Viewport(
	$cfg->
		layout('border')->
		items(array($actionpanel, $tabpanel->name()))
);
$viewport->jsrender();

$tabpanel->add(
	array('title'=>'New Tab', 'iconCls'=>'tabs', 'autoLoad'=>
		array('url'=>'extphptest.php?content1'),
		'closable'=>true
	)
);

new JsReady(JsWriter::get());
?>
</script>
Click the button: <div id='button1-div'></div>
<br />
A Grid Panel: <div id='grid1-div'></div>

<ul id="actions" class="x-hidden">
	<li>
		<a id="use" href="#">Bogus Item #1</a>
	</li>
	<li>
		<a id="create" href="#">Bogus Item #2</a>
	</li>
</ul>
 
<div id="tabs"></div>
</body>
</html>
