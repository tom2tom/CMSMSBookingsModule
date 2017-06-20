<?php
#----------------------------------------------------------------------
# Module: Booker - a resource booking module
# Action: processreport
# Display bookings summary-information
# include-file for a specific type of report
#----------------------------------------------------------------------
# See file Booker.module.php for full details of copyright, licence, etc.
#----------------------------------------------------------------------
// see https://gonzalo123.com/2010/01/24/pivot-tables-in-php for related examples

$dt = new DateTime('@0',NULL);

$t = '';
$t2 = $this->Lang('title_overview');
/*TODO supplement with interval-description if relevant, stamps 'showfrom','showto'
S to E, S and after, E and before
c.f. $this->GetPreference('dateformat') or $idata['dateformat']
*/
$tplvars['title'] = $this->Lang('report_title',$t,$t2);

//TODO support start/end time-limit(s) per $params['startchooser','endchooser'] if relevant
$sql =<<<EOS
SELECT slotstart,slotlen,1 AS bkg
FROM $this->DispTable
WHERE displayed>0
ORDER BY slotstart,item_id
EOS;
//$args = array();
$data = $db->GetArray($sql);

if ($data) {
	$ic = count($data);
	for ($i = 0; $i < $ic; $i++) {
		$dt->setTimestamp($data[$i]['slotstart']);
		$data[$i]['year'] = $dt->format('Y');
		$data[$i]['month'] = 'M'.(string)($dt->format('n')-1);
	}

	$pivoton = array('year','month');
	$group = null;
	$groupvalue = array('bkg','slotlen');
	$total = $this->Lang('total');
	$subtotal = $this->Lang('subtotal');

	$funcs = new Booker\Pivot2($data, $pivoton, $group, $groupvalue,
		TRUE, //include relevant Pivotbase::TYPE_*
		FALSE, //exclude  per-line subtotals
		TRUE, //include pivoted-field subtotals
		TRUE, //include whole-table totals 
		$total,
		$subtotal
	);
	$pivoted = $funcs->fetch();
	unset($data);

	if ($pivoted) {
		$rs = $db->SelectLimit('SELECT item_id FROM '.$this->ItemTable.' WHERE item_id<'.Booker::MINGRPID, 1, 1);
		if (!$rs->EOF) {
			$row = $rs->FetchRow();
			$slen = $utils->GetInterval($this,$row['item_id'],'slot');
		} else {
			$slen = 3600; //TODO better default from module preferences
		}
		$rs->Close();
		if ($slen <= 86400) {
			$slen = 3600;
			$t = $this->Lang('title_hours');
		} elseif ($slen < 604800) {
			$slen = 86400;
			$t = $this->Lang('title_days');
		} elseif ($slen < 2592000) {
			$slen = 604800;
			$t = $this->Lang('title_weeks');
		} else {
			$slen = 2592000;
			$t = $this->Lang('title_months');
		}

		$translates = array(
			'bkg'=>$this->Lang('count'),
			'month'=>$this->Lang('title_month'),
			'slotlen'=>$t,
			'year'=>$this->Lang('title_year')
		);
		$months = array();
		foreach (explode(',',$this->Lang('longmonths')) as $k => $val) {
			$months['M'.$k] = $val;
		}
		$translates += $months;

		$row = reset($pivoted);
		//interpet titles, and log row-indices of *\'slotlen'
		$works = array();
		foreach ($row as $t2 => $val) {
			$parts = explode('\\',$t2);
			foreach ($parts as $k => &$val) {
				switch ($val) {
				 case 'type':
					$parts = FALSE; //type field won't be displayed
					break 2;
				 case 'slotlen':
					$works[] = $t2;
					break;
				}
				if ($val[0] == 'M') {
					unset($parts[$k]);
				} elseif (array_key_exists($val,$translates)) {
					$val = $translates[$val];
				}
			}
			unset($val);
			if ($parts) {
				$coltitles[] = implode('<br />',$parts);
			}
		}

		$theme = ($this->before20) ? cmsms()->get_variable('admintheme'):
			cms_utils::get_theme_object();
		$t2 = $this->Lang('tip_seetype',$this->Lang('booking_multi'));
		$icon_view = $theme->DisplayImage('icons/system/view.gif',$t2,'','','systemicon');

		$ic = count($pivoted);
		for ($i = 0; $i < $ic; $i++) {
			$row = $pivoted[$i];
			$dataline = ($row['type'] == Booker\PivotBase::TYPE_LINE);
			unset($row['type']);
			$yid = $row['year'];
			if ($dataline) {
				$current = $yid;
				$row['month'] = $months[$row['month']];
			} elseif (strpos($yid,$subtotal) !== FALSE) {
				$row['year'] = str_replace('year',$current,$yid);
			}
			//interpret *\'slotlen'
			foreach ($works as $t2) {
				if (isset($row[$t2])) {
					$row[$t2] = round(($row[$t2]/$slen),1);
				}
			}

			$oneset = new stdClass();
			$oneset->fields = array_values($row);
			if ($display) {
				$oneset->view = ($dataline) ? $this->CreateLink($id,$params['action'],'',$icon_view,
					array('filter'=>1)) : NULL; //TODO $params[]
			}
			$output[] = $oneset;
		}
	}
}
