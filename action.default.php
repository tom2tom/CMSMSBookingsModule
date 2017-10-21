<?php
#----------------------------------------------------------------------
# Module: Booker - a resource booking module
# Action: default
# Default frontend action for the module
#----------------------------------------------------------------------
# See file Booker.module.php for full details of copyright, licence, etc.
#----------------------------------------------------------------------
//parameter keys filtered out before redirect etc
$localparams = [
	'action',
	'bkgid',
	'bookat',
	'cart',
	'clickat',
	'find',
	'item',
	'listformat',
	'message',
	'module',
	'newlist',
	'pick',
	'rangepick',
	'request',
	'slide+1',
	'slide+4',
	'slide+7',
	'slide-1',
	'slide-4',
	'slide-7',
	'toggle',
//	'view',
	'zoomin',
	'zoomout'
];

$utils = new Booker\Utils();
//$cache = $utils->GetCache();
if (!empty($params['showfrom'])) {
	$t = $params['showfrom']; //use supplied value of this, regardless of cache
} else {
	$t = FALSE;
}
//some cached parameters, if present, are omitted from the  merge with current $params
$utils->UnFilterParameters($params,['booker_id','fee','itempick','newlist']);

if (!empty($params['itempick'])) {
	$item_id = (int)$params['itempick'];
} elseif (!empty($params['item'])) {
	$item_id = $utils->GetItemID($this,$params['item']);
} elseif (!empty($params['item_id'])) {
	$item_id = (int)$params['item_id'];
} else {
	$item_id = FALSE;
}
if (!$item_id) {
	$tplvars = [
		'title_error' => $this->Lang('error'),
		'message' => $this->Lang('err_parm'),
		'pagenav' => NULL
	];
	echo Booker\Utils::ProcessTemplate($this,'error.tpl',$tplvars);
	return;
}

$params['item_id'] = $item_id;
if ($t) {
	$params['showfrom'] = $t;
}

$cache = $utils->GetCache();

// get relevant data for the resource/group
$idata = ['item_id' => $item_id] + $utils->GetItemProperties($this,$item_id,
['name','description','membersname','image','available', //'pickthis','pickmembers',
'slottype','slotcount','latitude','longitude','timezone','grossfees',
'dateformat','timeformat','listformat','stylesfile','bulletin']); //'*');
// get/setup cart for bookings
$cart = $utils->RetrieveCart($cache,$params,'',$idata['grossfees']); //TODO item-specific context
$is_group = ($item_id >= Booker::MINGRPID);

if (!isset($params['firstpick'])) {
	$params['firstpick'] = ($is_group) ? $item_id : FALSE;
}

$dtw = new DateTime('@0',NULL);
if (isset($params['showfrom'])) {
	if (is_numeric($params['showfrom']))
		$dtw->setTimestamp($params['showfrom']);
	elseif (strtotime($params['showfrom']))
		$dtw->modify($params['showfrom']);
	else {
		$st = $utils->GetZoneTime($idata['timezone']);
		$dtw->setTimestamp($st);
		$params['message'] = $this->Lang('err_system').' '.$params['showfrom'];
	}
} else {
	$st = $utils->GetZoneTime($idata['timezone']);
	$dtw->setTimestamp($st);
}
$dtw->setTime(0,0,0); //force day-start
$params['showfrom'] = $dtw->getTimestamp();

$params['resume'] = ['default']; //redirects can [eventually] get back to here

$publicperiods = $utils->RangeNames($this,[0,1,2,3]);

if (isset($params['rangepick'])) //first pref, so we can detect changes
	$range = $params['rangepick'];
elseif (isset($params['range']))
	$range = $params['range'];
else
	$range = $utils->GetDefaultRange($this,$item_id);

if (is_numeric($range)) {
	$range = (int)$range;
	if ($range < 0 || $range >= count($publicperiods))
		$range = $utils->GetDefaultRange($this,$item_id);
} elseif ($range == '') {
	$range = Booker::RANGEDAY;
} else { //assume text
	$range = strtolower($params['range']); //english-only, no need for mb_convert_case()
	$t = array_search($range,$publicperiods);
	if ($t !== FALSE)
		$range = $t;
	else
		$range = $utils->GetDefaultRange($this,$item_id);
}
$params['range'] = $range;

if (isset($params['request'])) { //'book' button clicked or cell double-clicked
	$newparms = $utils->FilterParameters($params,$localparams);
	if (!empty($params['bkgid'])) { //target is already booked
		$newparms['bkr_bkgid'] = $params['bkgid']; //normally excluded, but this time we want it
	}
	if (!empty($params['clickat'])) {
		$dtw->modify($params['clickat']);
		$st = $dtw->getTimestamp();
		if ($range == Booker::RANGEYR) {
			//set nowish start-time on the selected day
			$t = $utils->GetZoneTime($idata['timezone']);
			$dtw->setTimestamp($t);
			$t = (int)$dtw->format('G');
			$slen = $utils->GetInterval($this,$item_id,'slot');
			$st += (int)($t*3600/$slen)*$slen + 3600;
		}
		$newparms['bkr_bookat'] = $st;
	}
	$this->Redirect($id,'dobooking',$returnid,$newparms);
} elseif (isset($params['find'])) {
	if (!empty($params['clickat'])) {
		$dtw->modify($params['clickat']);
		$params['bookat'] = $dtw->getTimestamp();
	}
	$newparms = $utils->FilterParameters($params,$localparams);
	$this->Redirect($id,'findbooking',$returnid,$newparms);
} elseif (isset($params['cart'])) {
	$params['task'] = 'see'; //facilitate buttons-creation
	$newparms = $utils->FilterParameters($params,$localparams);
	$this->Redirect($id,'opencart',$returnid,$newparms);
}

//show bookings-data as table?
$showtable = (empty($params['view']) || $params['view'] == 'table');
if (isset($params['toggle']))
	$showtable = !$showtable;
if (isset($params['altview'])) { //should never happen if js is enabled
//	$this->Crash();
}
$params['view'] = ($showtable)?'table':'list';

$arr = preg_grep('/^slide.?\d/',array_keys($params));
if ($arr) {
	$t = (int)substr(reset($arr),5);
	$arr = $utils->DisplayIntervals(); //non-translated form
	$v = $arr[$range];
	if (!($t == 1 || $t == -1))
		$v .= 's';
	$dtw->modify($t.' '.$v);
	$params['showfrom'] = $dtw->getTimestamp();
} elseif (isset($params['zoomin'])) {
	if ($range > 0)
		--$range;
} elseif (isset($params['zoomout'])) {
	if ($range < count($publicperiods) - 1)
		++$range;
}

if (!empty($params['newlist'])) {
	$idata['listformat'] = $params['listformat'];
}

$jsfuncs = []; //script accumulator
$jsloads = []; //document-ready funcs
$jsincs = []; //js includes
$baseurl = $this->GetModuleURLPath();

$jsloads[] = <<<EOS
 $('#needjs').css('display','none');
EOS;

$tplvars = ['needjs'=>$this->Lang('needjs')];

if (isset($params['message']))
	$tplvars['message'] = $params['message'];

$hidden = $utils->FilterParameters($params,$localparams);
$tplvars['startform'] = $this->CreateFormStart($id,'default',$returnid,'POST','','','',$hidden);
$tplvars['endform'] = $this->CreateFormEnd();

$hidden = [];
$names = ['showfrom'];
if ($showtable) {
	$names[] = 'clickat';
	$names[] = 'bkgid';
} else {
	$names[] = 'newlist';
}
foreach ($names as $one) {
	$hidden[] = $this->CreateInputHidden($id,$one,'');
}
$tplvars['hidden'] = $hidden;

if (!empty($idata['name'])) {
	$t = $this->Lang('title_booksfor',$idata['name'],'');
} else {
	$typename = ($is_group) ? $this->Lang('group'):$this->Lang('item');
	$t = $this->Lang('title_noname',$typename,$item_id);
	$t = $this->Lang('title_booksfor',$t,'');
}

switch ($range) {
 case Booker::RANGEDAY:
 	$t .= ' '.$utils->IntervalFormat($this,$dtw,$idata['dateformat'],TRUE);
	break;
 case Booker::RANGEWEEK:
 case Booker::RANGEMTH:
 case Booker::RANGEYR:
	list($dtw,$dte) = $utils->GetRangeLimits($params['showfrom'],$range);
	$s = $dtw->format('Y');
	$dte->modify('-1 day');
	$withyr = ($dte->format('Y') != $s);
	$s = $utils->IntervalFormat($this,$dtw,$idata['dateformat'],$withyr);
	$e = $utils->IntervalFormat($this,$dte,$idata['dateformat'],TRUE);
	$t .= ' '.$this->Lang('showrange',$s,$e);
	break;
}
$tplvars['title'] = $t;
if (!empty($idata['description']))
	$tplvars['desc'] = Booker\Utils::ProcessTemplateFromData($this,$idata['description'],$tplvars);

$t = $utils->GetImageURLs($this,$idata['image'],$idata['name']);
if ($t) {
	$tplvars['pictures'] = $t;
}
$t = $idata['bulletin'];
if ($t && $t != '<!---->') {
	$tplvars['bulletin'] = $t;
}

//action-buttons
$intrvl = $publicperiods[$range];
$mintrvl = $utils->RangeNames($this,$range,TRUE); //plural variant

$tplvars['actionstitle'] = $this->Lang('display');
$actions1 = [];
$chooser = $utils->GetItemPicker($this,$id,'itempick',$params['firstpick'],$item_id);
if ($chooser) {
	$actions1[] = $chooser;
	$jsloads[] = <<<EOS
 $('#{$id}itempick').change(function() {
  $(this).closest('form').trigger('submit');
 });
EOS;
}

/* DISABLE FOCUS-HELP
if ($showtable) {
	$t = $this->Lang('tip_info');
	$tplvars['focusicon'] = '<a href="" onclick="return helptogl(this);"><img src="'
		.$baseurl.'/images/info-small.png" alt="info-toggle icon" title="'.$t.'" border="0" /></a>';
	if ($chooser) {
		if ($idata['membersname']) {
			$s = $idata['membersname'];
		} else {
			$s = $this->Lang('itemv_multi');
		}
		$t = $this->Lang('help_focus2', $s).'<br />';
	} else {
		$t = '';
	}
	$t .= $this->Lang('help_focus').$this->Lang('help_focus3', $this->Lang('book'));
	$tplvars['focushelp'] = $t;

	$jsfuncs[] = <<<EOS
function helptogl(el) {
 var \$cd = $(el).closest('div'),
  \$hd = \$cd.next()
 if (\$hd[0].style.display != 'none') {
  \$cd.css('float','');
  \$hd.slideUp(200);
 } else {
  \$cd.css('float','left');
  \$hd.slideDown(200);
 }
 return false;
}
EOS;
}
*/

//button-icons from sprite with 10 'panels' (uses CSS3 styling)
$actions1[] = $this->_CreateInputIcon($id,'pick',$baseurl.'/images/tools.png',
	'16em 2em','0 50%','title="'.$this->Lang('tip_calendar').'"');
if ($range == Booker::RANGEDAY) {
	$t = '-7';
	$xtra = 'title="'.$this->Lang('tip_backN',7,$mintrvl).'"';
} else {
	$t = '-4';
	$xtra = 'title="'.$this->Lang('tip_backN',4,$mintrvl).'"';
}
$actions1[] = $this->_CreateInputIcon($id,'slide'.$t,$baseurl.'/images/tools.png',
	'16em 2em','-2em 50%',$xtra);
$actions1[] = $this->_CreateInputIcon($id,'slide-1',$baseurl.'/images/tools.png',
	'16em 2em','-4em 50%','title="'.$this->Lang('tip_back1',$intrvl).'"');
$actions1[] = $this->_CreateInputIcon($id,'slide+1',$baseurl.'/images/tools.png',
	'16em 2em','-6em 50%','title="'.$this->Lang('tip_forw1',$intrvl).'"');
if ($range == Booker::RANGEDAY) {
	$t = '+7';
	$xtra = 'title="'.$this->Lang('tip_forwN',7,$mintrvl).'"';
} else {
	$t = '+4';
	$xtra = 'title="'.$this->Lang('tip_forwN',4,$mintrvl).'"';
}
$actions1[] = $this->_CreateInputIcon($id,'slide'.$t,$baseurl.'/images/tools.png',
	'16em 2em','-8em 50%',$xtra);
/*
$xtra = ($range == Booker::RANGEDAY) ? ' disabled="disabled"' : '';
$actions1[] = $this->_CreateInputIcon($id,'zoomin',$baseurl.'/images/tools.png',
	'20em 2em','-10em 50%','title="'.$this->Lang('tip_zoomin').'"'.$xtra);
$xtra = ($range == Booker::RANGEYR) ? ' disabled="disabled"' : '';
$actions1[] = $this->_CreateInputIcon($id,'zoomout',$baseurl.'/images/tools.png',
	'20em 2em','-12em 50%','title="'.$this->Lang('tip_zoomout').'"'.$xtra);
*/
$choices = $utils->RangeNames($this,[0,1,2,3],FALSE,TRUE); //capitalised
$actions1[] = $this->CreateInputDropdown($id,'rangepick',array_flip($choices),-1,$range,
	'id="'.$id.'rangepick" title="'.$this->Lang('tip_display').'"');
$actions1[] = $this->_CreateInputIcon($id,'find',$baseurl.'/images/tools.png',
	'16em 2em','-10em 50%','title="'.$this->Lang('tip_find').'"');
$t = ($showtable) ? '-12':'-14';
$actions1[] = $this->_CreateInputIcon($id,'toggle',$baseurl.'/images/tools.png',
	'16em 2em',$t.'em 50%','title="'.$this->Lang('tip_otherview').'"');

$jsloads[] = <<<EOS
 $('#{$id}rangepick').change(function() {
  $(this).closest('form').trigger('submit');
 });
EOS;

//$actions2 = [];
if (!$showtable) {
	if ($is_group) 	{
		$choices = [
		$this->Lang('start+user')=>Booker::LISTSU,
		$this->Lang('resource+start')=>Booker::LISTRS,
		$this->Lang('user+resource')=>Booker::LISTUR,
		$this->Lang('user+start')=>Booker::LISTUS
		];
	} else {
		$choices = [
		$this->Lang('start+user')=>Booker::LISTSU,
		$this->Lang('user+resource')=>Booker::LISTUR,
		$this->Lang('user+start')=>Booker::LISTUS
		];
	}
	$actions1[] = $this->CreateInputDropdown($id,'listformat',$choices,-1,$idata['listformat'],
		'id="'.$id.'listformat" title="'.$this->Lang('tip_listtype').'" style="margin-top:0.5em;"');
	$jsloads[] = <<<EOS
 $('#{$id}listformat').change(function() {
  $('#{$id}newlist').val(1);
  $(this).closest('form').trigger('submit');
 });
EOS;
}
//$tplvars['actions2'] = $actions2;
$tplvars['actions1'] = $actions1;

$t = $this->CreateInputSubmit($id,'request',$this->Lang('book'),
	'title="'.$this->Lang('tip_book').'"');
$tplvars['book'] = str_replace('id="'.$id.'request"','id="btngo"',$t);
/*
$xtra = ($cart->seemsEmpty()) ?
	'disabled="disabled" title="'.$this->Lang('tip_cartempty').'"':
	'title="'.$this->Lang('tip_cartshow').'"';
$tplvars['cart'] = $this->CreateInputSubmit($id,'cart',$this->Lang('cart'),$xtra);
*/

$funcs2 = new Booker\Display($this);
if ($showtable) {
	$funcs2->Tabulate($tplvars,$idata,$params['showfrom'],$range);
	$tplname = 'defaulttable.tpl';
} else { //bookings-data text
	$funcs2->Listify($tplvars,$idata,$params['showfrom'],$range);
	$tplname = 'defaultlist.tpl';
}

$jsfuncs[] = <<<EOS
function isTouchable() {
 var eventName='ontouchstart',
  el=document.createElement('input'),
  supported=(eventName in el);
 if (!supported) {
  el.setAttribute(eventName,'return;');
  supported=typeof el[eventName] == 'function';
 }
 el=null;
 return supported;
}
function init_tooltip(target, tooltip) {
 if ($(window).width() < tooltip.outerWidth() * 1.5)
  tooltip.css('max-width', $(window).width() / 2);
 else
  tooltip.css('max-width', 340);

 var pos_left = target.offset().left + (target.outerWidth() / 2) - (tooltip.outerWidth() / 2),
  pos_top = target.offset().top - tooltip.outerHeight() - 20;

 if (pos_left < 0) {
  pos_left = target.offset().left + target.outerWidth() / 2 - 20;
  tooltip.addClass('left');
 } else
  tooltip.removeClass('left');

 if (pos_left + tooltip.outerWidth() > $(window).width()) {
  pos_left = target.offset().left - tooltip.outerWidth() + target.outerWidth() / 2 + 20;
  tooltip.addClass('right');
 } else
  tooltip.removeClass('right');

 if (pos_top < 0) {
  var pos_top = target.offset().top + target.outerHeight();
  tooltip.addClass('top');
 } else
  tooltip.removeClass('top');

 tooltip.css({
  left: pos_left,
  top: pos_top
 }).animate({
  top: '+=10',
  opacity: 1
 }, 50);
}
function remove_tooltip(target, tooltip, tip) {
 tooltip.animate({
  top: '-=10',
  opacity: 0
 }, 50, function() {
  $(this).remove();
 });

 target.attr('title', tip);
}
EOS;

if ($showtable) {
//CHECKME PURPOSE booking-table th click() handler
	$jsfuncs[] = <<<EOS
function slot_activate(ev,el) {
 if (el === undefined) {
  el=this;
 }
 var idx = $(el).index();
 if (idx === 0) //labels col
  return;
 slot_record(el);
 var bkid = $(el).attr('id');
 if (typeof bkid != 'undefined')
  $('#{$id}bgkid').val(bkid);
 $('#btngo').click();
}
function slot_record(el) {
 var idx = $(el).index(),
  table = $('#scroller')[0],
  dt = table.rows[0].cells[idx].getAttribute('iso');
 idx = $(el).parent().index();
 dt += table.rows[idx+1].cells[0].getAttribute('iso');
 $('#{$id}clickat').val(dt);
}
var focus = null;
function slot_focus(ev,el) {
 if (focus != null) {
  $(focus).removeClass('slotfocus');
 }
 if (el === undefined) {
  focus=this;
 } else {
  focus=el;
 }
 $(focus).addClass('slotfocus');
 slot_record(focus);
 var btn = $('#btngo');
 btn.addClass('btnfocus');
 setTimeout(function() {
  btn.removeClass('btnfocus');
  btn[0].focus();
 },4000);
}
function col_focus(ev,el) {
 if (el === undefined) {
  el=this;
 }
 var idx = $(el).index(),
  table = $('#scroller')[0],
  dt = table.rows[0].cells[idx].getAttribute('iso');
 dt += table.rows[1].cells[0].getAttribute('iso');
 $('#{$id}clickat').val(dt);
}
EOS;
	$jsincs[] = <<<EOS
<script type="text/javascript" src="{$baseurl}/lib/js/tableHeadFixer.min.js"></script>
EOS;
 	$jsloads[] = <<<EOS
 var \$table = $('#scroller');
 \$table.tableHeadFixer({'left':1});
 \$table.find('th.periodname').click(col_focus);
 var \$cells = \$table.find('td');
 \$cells.click(slot_focus);
 if (!isTouchable()) {
  \$cells.dblclick(slot_activate);
 }
EOS;
} else { // !showtable
	$jsloads[] = <<<EOS
 var \$cells = [];
EOS;
}

$jsloads[] = <<<EOS
 $(document).find('input.cms_submit,select').add(\$cells).on('mouseenter', function() {
  var target = $(this),
  titletip = target.attr('title');

  if (!titletip)
   return false;

  target.removeAttr('title').on('mouseleave', function() {
   remove_tooltip(target, tooltip, titletip);
  });
  
  var tooltip = $('<div class="tooltip">'+titletip+'</div>');
  tooltip.css('opacity', 0)
   .appendTo('body')
   .on('click', function () {
    remove_tooltip(target, tooltip, titletip);
   });

 init_tooltip(target, tooltip);
 $(window).on('resize', function () {
   init_tooltip(target, tooltip);
  });
 });
EOS;

$jsincs[] = <<<EOS
<script type="text/javascript" src="{$baseurl}/lib/js/pikaday.min.js"></script>
<script type="text/javascript" src="{$baseurl}/lib/js/pikaday.jquery.min.js"></script>
<script type="text/javascript" src="{$baseurl}/lib/js/php-date-formatter.min.js"></script>
EOS;

$nextm = $this->Lang('nextm');
$prevm = $this->Lang('prevm');
//js wants quoted period-names
$t = $this->Lang('longmonths');
$mnames = "'".str_replace(",","','",$t)."'";
$t = $this->Lang('longdays');
$dnames = "'".str_replace(",","','",$t)."'";
$t = $this->Lang('shortdays');
$sdnames = "'".str_replace(",","','",$t)."'";

$jsloads[] = <<<EOS
 $('#{$id}pick').click(function(ev) {
  ev.preventDefault();
  return false;
 }).pikaday({
  field: document.getElementById('{$id}showfrom'),
  i18n: {
   previousMonth: '$prevm',
   nextMonth: '$nextm',
   months: [$mnames],
   weekdays: [$dnames],
   weekdaysShort: [$sdnames]
  },
  onClose: function() {
   if('_d' in this && this._d) {
    var fmt = new DateFormatter();
    var dt = fmt.formatDate(this._d,'Y-m-d');
    $('#{$id}showfrom').val(dt);
    $(this._o.trigger).closest('form').trigger('submit');
   }
  }
 });
EOS;

$stylers = <<<EOS
<link rel="stylesheet" type="text/css" href="{$baseurl}/css/public.css" />
<link rel="stylesheet" type="text/css" href="{$baseurl}/css/pikaday.min.css" />
EOS;

if ($idata['stylesfile']) {
	$customcss = $utils->GetStylesURL($this,$item_id);
	if ($customcss) {
		$stylers .= <<<EOS
<link rel="stylesheet" type="text/css" href="{$customcss}" />
EOS;
	}
}
//heredoc-var newlines are a problem for in-js quoted strings, so ...
$stylers = preg_replace('/[\\n\\r]+/','',$stylers);
$t = <<<EOS
var linkadd = '$stylers',
 \$head = $('head'),
 \$linklast = \$head.find("link[rel='stylesheet']:last");
if (\$linklast.length) {
 \$linklast.after(linkadd);
} else {
 \$head.append(linkadd);
}
EOS;
echo $utils->MergeJS(FALSE,[$t],FALSE);

$jsall = $utils->MergeJS($jsincs,$jsfuncs,$jsloads);
unset($jsincs);
unset($jsfuncs);
unset($jsloads);

echo Booker\Utils::ProcessTemplate($this,$tplname,$tplvars);
if ($jsall) {
	echo $jsall; //inject constructed js after other content
}
