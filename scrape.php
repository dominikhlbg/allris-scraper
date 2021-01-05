<?php
// MIT License
//
// Copyright (c) 2012-2021 Dominik Homberger (dominikhlbg@gmail.com)
//
// Permission is hereby granted, free of charge, to any person obtaining a copy
// of this software and associated documentation files (the "Software"), to deal
// in the Software without restriction, including without limitation the rights
// to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
// copies of the Software, and to permit persons to whom the Software is
// furnished to do so, subject to the following conditions:
//
// The above copyright notice and this permission notice shall be included in all
// copies or substantial portions of the Software.
//
// THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
// IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
// FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
// AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
// LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
// OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
// SOFTWARE.

error_reporting(0);
set_time_limit(0);
$log = fopen("logfile.txt", "a");
function writelog($txt) {
	global $log;
	fwrite($log, date('d.m.Y H:i:s').' '.$txt);
}
//print file_get_contents('https://www.hagen.de/ngproxy/0df8ac9d5b552362d80ec1d51f2a9611320fc4fb');
$config = array(
				'BASEURL'=>'https://www.hagen.de',
				# URL-Format für Sitzungskalender
				'URI_CALENDAR'=>'/ngproxy/0df8ac9d5b552362d80ec1d51f2a9611320fc4fb?kaldatvon=%s.%s&kaldatbis=%s.%s',
				# URL-Format für Sitzungs-Detailseiten
				'URI_SESSION_DETAILS'=> '/ngproxy/ee30f23b7cec2e7c50285f540b01a06451352711?SILFDNR=%s',
				'TMP_FOLDER'=>'tmp',
				'ATTACHMENTFOLDER'=> 'attachments'
				);
$options = array(
				 'verbose'=>1,
				 'simulate'=>0);

class DataStore {
	private $connection=NULL;
    function __construct($db_name='dominikhlbg_de', $db_host='localhost', $db_user='root', $db_password='') {
        try {
			$this->connection = mysql_connect($db_host, $db_user, $db_password);
			mysql_select_db($db_name, $this->connection);
			unset($db_host); unset($db_user); unset($db_password); unset($db_name);
			mysql_query("SET NAMES 'utf8'", $this->connection);
			mysql_query("SET CHARACTER SET 'utf8'", $this->connection);
		}
        catch(Exception $e) {
            printf ("Error : %s");// , $e.args[0], $e.args[1]
            exit;
		}
	}

    function execute($sql) {
        if(!mysql_query($sql, $this->connection)) {//, $values)
		writelog( mysql_errno($this->connection) . ": " . mysql_error($this->connection) . "\n");
		writelog( $sql. "\n");
		exit;
		}
	}

    function get_rows($sql) {
        try {
            $result=mysql_query($sql, $this->connection);
            $rows = [];
			while ($row = mysql_fetch_assoc($result)) {
				$rows[]=$row;
			}
            return $rows;
		}
        catch(Exception $e) {
            //printf( "Error %d: %s" , e.args[0], e.args[1]);
		}
	}

    function save_rows($table, $data, $unique_keys) {
		$values = [];
		$sql = 'INSERT IGNORE INTO ' . $table . ' (`' .implode('`, `',array_keys($data)) . '`)';
		$sql2 = ' VALUES (';
		$placeholders = [];
		foreach(array_keys($data) as $el) {
			$placeholders[]='\''.mysql_real_escape_string(utf8_encode($data[$el])).'\'';
		}
		$sql2 .= implode(", ",$placeholders) . ')';
		$sql .= $sql2;
		if (isset($unique_keys)) {
			$sql3 = ' ON DUPLICATE KEY UPDATE ';
			$updates = [];
			foreach(array_keys($data) as $el) {
				if (!in_array($el, $unique_keys)) {
					$updates[]='`'.mysql_real_escape_string($el).'`' . "=".'\''.mysql_real_escape_string(utf8_encode($data[$el])).'\'';
				}
			}
			if (count($updates) > 0) {
				$sql3 .= implode(", ",$updates);
				$sql .= $sql3;
			}
		}
		#print sql
		$this->execute($sql);
		
		/*foreach($data as $key => $value)
		if(strlen($value)>=100)
		$data[$key]='strlen('.strlen($value).')';
		print_r($data);*/
	}

    function get_submissions() {
        return self.get_rows('SELECT * FROM submissions ORDER BY submission_date');
	}

    function get_requests() {
        return self.get_rows('SELECT * FROM requests ORDER BY request_date');
	}

    function get_agendaitems_by_submission_id($submission_id) {
        return self.get_rows(sprintf('SELECT * FROM agendaitems2submissions
            LEFT JOIN agendaitems ON agendaitems2submissions.agendaitem_id=agendaitems.agendaitem_id
            LEFT JOIN sessions ON sessions.session_id=agendaitems.session_id
            LEFT JOIN committees ON committees.committee_id=sessions.committee_id
            WHERE submission_id=%d
            ORDER BY session_date, session_time_start', $submission_id));
	}

    function get_attachments_by_submission_id($submission_id) {
        return self.get_rows(sprintf('SELECT * FROM submissions2attachments
            LEFT JOIN attachments ON submissions2attachments.attachment_id=attachments.attachment_id
            LEFT JOIN agendaitems2attachments ON agendaitems2attachments.attachment_id=attachments.attachment_id
            LEFT JOIN agendaitems ON agendaitems.agendaitem_id=agendaitems2attachments.agendaitem_id
            LEFT JOIN sessions ON sessions.session_id=agendaitems.session_id
            LEFT JOIN committees ON committees.committee_id=sessions.committee_id
            WHERE submission_id=%d
            ORDER BY session_date, session_time_start', $submission_id));
	}
}

function scrape_all($regex,$text,$fallback='') {
	$regex=str_replace("\r\n",'\s*',$regex);
	return (@preg_match_all('['.$regex.']ism',$text,$result, PREG_SET_ORDER)!==false)?format_values($result):$fallback;
}

function scrape($regex,$text,$fallback='') {
	$regex=str_replace("\r\n",'\s*',$regex);
	return (@preg_match('['.$regex.']ism',$text,$result)!==false)?(isset($result[1])?format_value($result[1]):$fallback):$fallback;
}

function file_get_content1($url) {
	$url = format_url($url);
	if(strpos($url,'?')!==false) {
		list($url,$params)=explode('?',$url);
		$opts = array('http'=>array(
									'header'  => 'Content-type: application/x-www-form-urlencoded',
									'method'=>"POST",
									'content'=>$params
									));
		$context = stream_context_create($opts);
		$content = file_get_contents($url, false, $context);
	} else
		$content = file_get_contents($url);
		
	foreach($http_response_header as $value) {
		$arr=explode(': ',$value,2);
		$header_array[strtolower($arr[0])]=isset($arr[1])?$arr[1]:'';
	}
	return array('headers'=>$header_array,'content'=>$content);
}
function file_get_content($input_url) {
	$url = format_url($input_url);
	$ch = curl_init();
	if(strpos($url,'?')!==false) {
		list($url,$params)=explode('?',$url);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
	}
	curl_setopt($ch, CURLINFO_HEADER_OUT, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla");
	curl_setopt($ch, CURLOPT_HEADER, 1);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_VERBOSE, 1);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_TIMEOUT, 120);
	//curl_setopt( $ch, CURLOPT_COOKIESESSION, true );
	curl_setopt( $ch, CURLOPT_COOKIEJAR, 'cookies.txt' );
	curl_setopt( $ch, CURLOPT_COOKIEFILE, 'cookies.txt' );
	$response = curl_exec($ch);
	$http_request_header = curl_getinfo($ch, CURLINFO_HEADER_OUT);
	$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	$http_response_header = explode("\r\n",substr($response, 0, $header_size));
	$content = substr($response, $header_size);
	curl_close($ch);

	$header_array=array();
	foreach($http_response_header as $value) {
		if($value=='') continue;
		$arr=explode(': ',$value,2);
		$header_array[strtolower($arr[0])]=isset($arr[1])?$arr[1]:'';
	}
	writelog( 'URL:'.$input_url.' SIZE:'.strlen($content)."\n");
	return array('headers'=>$header_array,'content'=>$content);
}
function rolling_curl($urls) {
	if(count($urls)==0) return;
	if(count($urls)==1) return array($urls[0]=>file_get_content($urls[0]));
	$options = array(
					CURLINFO_HEADER_OUT => true,
					CURLOPT_SSL_VERIFYPEER => 0,
					CURLOPT_SSL_VERIFYHOST => 0,
					CURLOPT_USERAGENT => "Mozilla",
					CURLOPT_HEADER => 1,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_VERBOSE => 1,
					CURLOPT_FOLLOWLOCATION => true,
					CURLOPT_TIMEOUT => 120,
					CURLOPT_COOKIEJAR => 'cookies.txt',
					CURLOPT_COOKIEFILE => 'cookies.txt');

    // make sure the rolling window isn't greater than the # of urls
    $rolling_window = 5;
    $rolling_window = (sizeof($urls) < $rolling_window) ? sizeof($urls) : $rolling_window;

    $master = curl_multi_init();
    $curl_arr = array();
	$requestMap=array();
	$inputURLs=array();

    // start the first batch of requests
    for ($i = 0; $i < $rolling_window; $i++) {
        $ch = curl_init();
		$url = format_url($urls[$i]);
		if(strpos($url,'?')!==false) {
			list($url,$params)=explode('?',$url);
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
		}
        $options[CURLOPT_URL] = $url;
        curl_setopt_array($ch,$options);
        curl_multi_add_handle($master, $ch);
		
		// Add to our request Maps
		$key = (string) $ch;
		$requestMap[$key] = $i;
		$inputURLs[$key]=$urls[$i];
    }

    do {
        while(($execrun = curl_multi_exec($master, $running)) == CURLM_CALL_MULTI_PERFORM);
        if($execrun != CURLM_OK)
            break;
        // a request was just completed -- find out which one
        while($done = curl_multi_info_read($master)) {
            $info = curl_getinfo($done['handle']);
            //if ($info['http_code'] == 200)  {
                $response = curl_multi_getcontent($done['handle']);
				
				$http_request_header = curl_getinfo($done['handle'], CURLINFO_HEADER_OUT);
				$header_size = curl_getinfo($done['handle'], CURLINFO_HEADER_SIZE);
				$http_response_header = explode("\r\n",substr($response, 0, $header_size));
				$content = substr($response, $header_size);
			
				$header_array=array();
				foreach($http_response_header as $value) {
					if($value=='') continue;
					$arr=explode(': ',$value,2);
					$header_array[strtolower($arr[0])]=isset($arr[1])?$arr[1]:'';
				}
				
                // request successful.  process output using the callback function.
				$key = (string)$done['handle'];
				writelog( 'URL:'.$inputURLs[$key].' SIZE:'.strlen($content).' KEY:'.$key."\n");
				$result[$urls[$requestMap[$key]]] = array('headers'=>$header_array,'content'=>$content);
				unset($requestMap[$key]);

                // start a new request (it's important to do this before removing the old one)
				if($i<count($urls)) {
					$ch = curl_init();
					$url = format_url($urls[$i]);
					if(strpos($url,'?')!==false) {
						list($url,$params)=explode('?',$url);
						curl_setopt($ch, CURLOPT_POST, 1);
						curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
					}
					$options[CURLOPT_URL] = $url;  // increment i
					curl_setopt_array($ch,$options);
					curl_multi_add_handle($master, $ch);

                    // Add to our request Maps
                    $key = (string) $ch;
                    $requestMap[$key] = $i;
					$inputURLs[$key]=$urls[$i];
					$i++;
				}

                // remove the curl handle that just completed
                curl_multi_remove_handle($master, $done['handle']);
            //}
        }
		// Block for data in / output; error handling is done by curl_multi_exec
		if ($running) {
			curl_multi_select($master, $options[CURLOPT_TIMEOUT]);
		}
    } while ($running);
   
    curl_multi_close($master);
	foreach($result as $key => $value) {
		if(strlen($value['content'])==0) {
			writelog( 'RETRY - URL:'.$key."\n");
			$result[$key] = file_get_content($key);
		}
	}
    return $result;
}
//64
function cleanup_identifier_string($string) {
	/*Bereinigt eine Dokumenten-ID und gibt sie zurück.*/
	if ($string<>'')
		return $string;
		return str_replace(' ', '',$string);
}

function format_url($url) {
	global $config;
	if($url[0]=='/')
	$url=$config['BASEURL'].$url;
	return $url;
}

function format_value($str) {
	$str=trim(utf8_decode(html_entity_decode($str)));
	return $str;
}
function format_values($array) {
	foreach($array as $key => $subarray) {
		foreach($subarray as $subkey => $value)
			$array[$key][$subkey]=trim(utf8_decode(html_entity_decode($value)));
	}
	return $array;
}

function file_error($filename) {
	if(filesize($filename)<=12000) {
		$content=file_get_contents($filename);
		if(strpos($content,'Datei oder Verzeichnis wurde nicht gefunden.')!==false) return false;
		if(strpos($content,'Sie sind nicht berechtigt, die Information zu sehen.')!==false) return false;
	}
	return true;
}
function createdir($dir) {
	if (!file_exists($dir))
		mkdir($dir);
}
function create_multi_dir($folders) {
	//Verzeichnisse erstellen
	$folders=explode('/',$folders);
	$startfolder=$folders[0];
	createdir($startfolder);
	array_shift($folders);
	foreach($folders as $folder) {
		$startfolder .='/'.$folder;
		createdir($startfolder);
	}
	return $startfolder;
}
function save_html($html,$filename,$folders,$overwrite=false) {
	save_file($html,$filename.'.html',$folders,$overwrite);
}
//Kalender speichern save_html($html,'2015-03.html','')
//Sitzungen speichern save_html($html,'126.html','2015-03/')
//Tagesordnungspunkt speichern save_html($html,'4523.html','2015-03/Ö 3.0/')
//Vorlage speichern save_html($html,'01.html','Vorlagen/2015/')
function save_file($content,$filename,$folders,$overwrite) {
	//Speichern: Kalender, Sitzungen, Tagesordnungspunkte, Vorlagen
	//Beispiel: Kalender/Sitzungen,Tagesordnungspunkte
	//			VorlageJahr/VorlageID
	
	$item0='allris_html';
	$startfolder=create_multi_dir($item0.'/'.$folders);
	$fullname=$startfolder.'/'.$filename;
	if(file_exists($fullname)) {
		if(!file_error($fullname)) $overwrite=true;
	}
	if(file_exists($fullname)&&!file_error($fullname)) $overwrite==true;
	//if(!file_exists($fullname)||(file_exists($fullname)&&((filesize($fullname)<=1400&&strpos(file_get_contents($fullname),'(Angefordertes Dokument nicht im Bestand)')===false)||$overwrite==true)))
	if(strlen($content)>0&&file_exists($fullname)&&md5($content)<>md5(file_get_contents($fullname)))
	file_put_contents($fullname,$content);
	//Datei schreiben
}

//93
function get_session_ids_html($year, $month) {
	global $options,$config;
	/*
	Scrapet alle publizierten Sitzungen zum gegebenen Monat des gegebenen
	Jahres und gibt die Sitzungs-IDs als Liste zurück.
	*/
	$ids = [];
	//Kalender aufrufen
	$html=file_get_content($config['BASEURL'].'/ngproxy/start/eyJhcHBsaWNhdGlvbklkIjoiQWxscmlzIEJ1ZXJnZXIifQ')['content'];
	$url=scrape('<li><strong>Sitzungen</strong></li> 
        <li> 
         <ul> 
          <li><a href="(.*?)" title="Sitzungstermine aller Gremien">Kalender</a></li>',$html);
	writelog( "Sitzungstermine aller Gremien ".$url."\n");
	$html=file_get_content($config['BASEURL'].$url)['content'];
	//Kalender aufrufen Ende
	$URI_CALENDAR=scrape('<form method="post" action="(.*?)" name="kaldatform" id="kaldatform" style="margin:0">',$html);
	$config['URI_CALENDAR']=sprintf($URI_CALENDAR.'?kaldatvon=%s.%s&kaldatbis=%s.%s', $month, $year, $month, $year);
	writelog( "Kalenderurl ".$config['URI_CALENDAR']."\n");
	$url = $config['BASEURL'].$config['URI_CALENDAR'];
	writelog( "Lade Sitzungen für Jahr ".$year.", Monat ".$month."\n");
	$urls=array();
	$urls[]=$url;
	$result=rolling_curl($urls);
	$html=$result[$url]['content'];
	$data = scrape_all('</form>\s*</td>\s*<td>\s*<form action="(?<formlink>.*?)" method="post" style="margin:0">\s*<input type="hidden" name="SILFDNR" value="(?<ksinr>\d*?)" />\s*<input type="submit" class="il1_to" value="TO" title="Tagesordnung" />\s*</form></td>\s*<td.*?><a href="(?<url>.*?)">(?<title>.*?)</a>', $html);
	foreach ($data as $key => $item) {
		$ids[]=array('id'=>$item['ksinr'],'url'=>$item['url'],'title'=>$item['title'],'formlink'=>$item['formlink'],'year'=>$year,'month'=>$month);
	}
	save_html($html,$month,'Sitzung/'.$year);
	if ($options['verbose']) {
		writelog( "Anzahl gefundene Sitzungen:". count($ids)."\n");
	}
	return $ids;
}

//113
function get_session_detail_url($session_id) {
	/*Gibt anhand einer Sitzungs-ID die Detail-URL zurück*/
	global $config;
	return $config['BASEURL'] . sprintf($config['URI_SESSION_DETAILS'], $session_id);
}

//118
function get_session_details_html($id_,$html) {
	/*
	Scrapet Details zur Sitzung mit der gegebenen ID
	und legt sie in der Datenbank ab.
	*/
	global $db,$options,$config;
	$id=$id_['id'];
	$url=$id_['url'];
	$title=$id_['title'];
	//$url = get_session_detail_url($id);
	writelog( "Lade Sitzung ". $id.' '.$url."\n");
	save_html($html,$id,'Sitzung/'.$id_['year'].'/'.$id_['month']);
	$html = str_replace('&nbsp;', ' ',$html);
	$data = [];
	$data['session_id'] = $id;
	$data['session_formlink'] = $id_['formlink'];
	$data['session_url'] = $url;
	$data['session_title'] = /*$data['session_identifier'] = */scrape('<td class="kb1">Bezeichnung:</td>\s*<td class="text1" colspan="3">(.*?)</td>', $html);
	//$data['committee_id']
	$data['committee_title'] = scrape('<td class="kb1">Gremium:</td>\s*<td class="text1" colspan="3"><a href=".*?">(.*?)</a></td>', $html);
	$data['session_date'] = get_date(trim(scrape('<td class="kb1">Datum:</td>\s*<td class="text2" nowrap="nowrap">.*?<a .*?>(.*?)</a></td>', $html)));
	$time = scrape('<td class="kb1">Zeit:</td>\s*<td class="text2" nowrap="nowrap">(.*?)</td>', $html);
	$starttime = '';
	$endtime = '';
	list($starttime, $endtime) = get_start_end_time($time);
	$data['session_time_start'] = $starttime;
	$data['session_time_end'] = $endtime;
	$data['session_room'] = scrape('<td class="kb1">Raum:</td>\s*<td colspan="3" class="text2">(.*?)</td>', $html);
	$data['session_location'] = scrape('<td class="kb1">Ort:</td>\s*<td colspan="3" class="text2">(.*?)</td>', $html);
	$data['session_state'] = scrape('<td class="kb1">Status:</td>\s*<td class="text3">(.*?)</td>', $html);
	$data['session_occasion'] = scrape('<td class="kb1">Anlass:</td>\s*<td class="text4">(.*?)</td>', $html);
	if (!$options['simulate'])
	$db->save_rows('ris_sessions', $data, ['session_id']);
	//if (isset($data['committee_title']) and ($data['committee_title'] <> '') and (!is_committee_in_db($data['committee_title'])))
	//	get_committee_details($data['committee_title']);
	get_attachments_html($id, $html, 'Sitzung/'.$id_['year'].'/'.$id_['month'].'/'.$data['session_id'],'session');
	get_agenda_html($id_, $html);
	//get_session_attendants($id);/*Sitzungsteilnehmer hat Hagen nicht*/
}

function get_content($html) {
	$doc = new DOMDocument();
	@$doc->loadHTML($html);
	$xpath = new DOMXpath($doc);
	$domNodeList = $xpath->query("*//table[@class='risdeco']/tbody/tr/td[@bgcolor='white']");
	$subNodeList = $xpath->query("*//table[@class='risdeco']/tbody/tr/td[@bgcolor='white']/table[@class='tk1']");
	if($subNodeList->length>0)
		foreach ($subNodeList as $subElement)
			$subElement->parentNode->removeChild($subElement);
	$content='';
	foreach ($domNodeList as $domElement) {
		$children  = $domElement->childNodes;
		foreach ($children as $child)
		$content .=$doc->saveHTML($child);
	}
	$content=utf8_decode(html_entity_decode($content));
	return $content;
}
function exists_images($content,$path) {
	$doc_content = new DOMDocument();
	@$doc_content->loadHTML($content);
	$xpath_content = new DOMXpath($doc_content);
	$domNodeList_content = $xpath_content->query("*//img");
	$i=1;
	foreach ($domNodeList_content as $image) {
		if(($image->getAttribute("width")<>''&&$image->getAttribute("width")<=23)||($image->getAttribute("height")<>''&&$image->getAttribute("height")<=23)) continue;
		$filename=$path.'/'.$i.''.($image->getAttribute("width")<>''&&$image->getAttribute("height")<>''?'_'.$image->getAttribute("width").'_'.$image->getAttribute("height").'':'');
		if(count(glob($filename.'.*'))==0) return false;
		$filename=glob($filename.'.*')[0];
		if(!file_exists($filename)||(file_exists($filename)&&filesize($filename)<=0)) return false;
		if(!file_error($filename)) return false;
		$i++;
	}
	return true;
}
function get_images($html,$content,$attachment_location) {
	$doc_html = new DOMDocument();
	@$doc_html->loadHTML($html);
	$xpath_html = new DOMXpath($doc_html);
	
	$doc_content = new DOMDocument();
	@$doc_content->loadHTML($content);
	$xpath_content = new DOMXpath($doc_content);
	$domNodeList_content = $xpath_content->query("*//img");
	
	$images=[];
	$i=1;
	foreach ($domNodeList_content as $image) {
		if(($image->getAttribute("width")<>''&&$image->getAttribute("width")<=23)||($image->getAttribute("height")<>''&&$image->getAttribute("height")<=23)) continue;
		$filename=$i.''.($image->getAttribute("width")<>''&&$image->getAttribute("height")<>''?'_'.$image->getAttribute("width").'_'.$image->getAttribute("height").'':'');
		$images[]=array('url'=>$image->getAttribute("src"),'filename'=>$filename);
		$i++;
	}
	if(count($images)==0) return false;
	
	writelog('Bilder laden: '.count($images)."\n");
	
	$folder = get_cache_path('');
	$urls=array();
	foreach($images as $image) {
		$image_file=glob($folder . '/' . $attachment_location . '/' .$image['filename'].'.*');
		if(count($image_file)==1&&file_exists($image_file[0])&&filesize($image_file[0])>0) {continue;}
		$urls[]=$image['url'];
	}
	$result=rolling_curl($urls);
	foreach($images as $image) {
		$image_file=glob($folder . '/' . $attachment_location . '/' .$image['filename'].'.*');
		if(count($image_file)==1&&file_exists($image_file[0])&&filesize($image_file[0])>0) {writelog('Image already exists: '.$image_file[0]."\n");
			$doctype=pathinfo($image_file[0], PATHINFO_EXTENSION);
			$attachment_filename=$image['filename'].'.'.$doctype;
		} else {
			$data=$result[$image['url']];
			$headers=$data['headers'];
			$doctype=explode(';',explode('/',$headers['content-type'])[1])[0];
			if($doctype=='jpeg') $doctype='jpg';
			if($doctype=='html') {writelog('Fehler Bild laden'."\n");continue;}
			$attachment_filename=$image['filename'].'.'.$doctype;
			writelog('Write the Image: '.$folder . '/' . $attachment_location . '/' . $attachment_filename."\n");
			file_put_contents($folder . '/' . $attachment_location . '/' . $attachment_filename,$data['content']);
		}
		$domNodeList_html = $xpath_html->query("*//img[@src=\"".$image['url']."\"]");
		foreach ($domNodeList_html as $domElement_html)
		$domElement_html->setAttribute("src",$attachment_filename);
	}
	return $doc_html->saveHTML();
}

//172
function get_agenda_html($session_id_, $html) {
	/*
	Liest die Tagesordnungspunkte aus dem HTML der Sitzungs-
	Detailseite.
	*/
	global $db,$options,$submission_queue,$agendaitem_arr;
	$session_id=$session_id_['id'];
	$publicto = scrape_all('<td class="text4" nowrap="nowrap">(<span style="background-color:#8ae234" title="(?<agenda_addition>.*?)">|.*?)<a href=".*?>(?<agenda_type>.*?) (?<agenda_topid>.*?)</a>(</span>|.*?)</td> 
             <td>.*?</td> 
             <td>(
              <form action="(?<agenda_formlink>.*?)" method="post" style="margin:0">
               <input type="hidden" name="TOLFDNR" value="(.*?)" />
               <input type="submit" class="il1_naz" value="NA" title="(?<agenda_result>.*?)" />
              </form>|.*?)</td> 
             <td(.*?| nowrap="nowrap")>(<a href="(?<agenda_url>.*?)">(?<agenda_title>.*?)</a>|(?<agenda_title2>.*?))</td> (
             <!--(?<id>.*?) --> |.*?)
             <td>(
              <form action="(?<vorlage_formlink>.*?)" method="post" style="margin:0">
               <input type="hidden" name="VOLFDNR" value="(?<vorlage_id>.*?)" />
               <input type="submit" class="il1_vo" value="VO" title="Vorlage" />
              </form>|.*?)</td> 
             <td(.*?| nowrap="nowrap")>(<a href="(?<vorlage_url>.*?)">(?<vorlage_title>.*?) </a>|.*?)</td> 
             <td>.*?</td>', $html);
	/*$nonpublicto = scrape_all('<td class="text4" nowrap="nowrap">(<span style="background-color:#8ae234" title="(?<agenda_addition>.*?)">|.*?)<a href=".*?>N (?<agenda_topid>.*?)</a>(</span>|.*?)</td> 
             <td> </td> 
             <td> </td> 
             <td class="text1">(?<agenda_title>.*?)</td> 
             <td> </td> 
             <td> </td> 
             <td> </td>', $html);*/
	
	//Ab hier die Detailseite der Agenda auslesen
	$all_items_by_id = [];
	if(count($publicto)>0)
	foreach ($publicto as $entry) {
		if($entry['id']<>'') {
			 $all_items_by_id[$entry['id']] = array(
				'agendaitem_id'=> $entry['id'],
				'agendaitem_public'=> $entry['agenda_type']<>'N'?1:0,
				'agendaitem_identifier'=> NULL,
				'session_id'=> $session_id,
				'agendaitem_result'=> NULL
			);
			$all_items_by_id[$entry['id']]['agendaitem_identifier'] = $entry['agenda_topid'];
			$folge=@explode('.',$entry['agenda_topid']);$folge[]=0;$folge[]=0;
			$all_items_by_id[$entry['id']]['agendaitem_identifier_num'] = $folge[0];
			$all_items_by_id[$entry['id']]['agendaitem_identifier_unum'] = $folge[1];
			$all_items_by_id[$entry['id']]['agendaitem_identifier_uunum'] = $folge[2];
			
			$all_items_by_id[$entry['id']]['agendaitem_subject'] = $entry['agenda_title']<>''?$entry['agenda_title']:$entry['agenda_title2'];
			$all_items_by_id[$entry['id']]['agendaitem_formlink'] = $entry['agenda_formlink'];
			$all_items_by_id[$entry['id']]['agendaitem_url'] = $entry['agenda_url'];
			$all_items_by_id[$entry['id']]['agendaitem_result'] = $entry['agenda_result'];
			$all_items_by_id[$entry['id']]['agendaitem_addition'] = $entry['agenda_addition'];
		} else {
			$folge=@explode('.',$entry['agenda_topid']);$folge[]=0;$folge[]=0;
			 $all_items_by_id[] = array(
				'agendaitem_public'=> $entry['agenda_type']<>'N'?1:0,
				'agendaitem_identifier'=> NULL,
				'session_id'=> $session_id,
				'agendaitem_identifier'=>$entry['agenda_topid'],
				'agendaitem_identifier_num'=>$folge[0],
				'agendaitem_identifier_unum'=>$folge[1],
				'agendaitem_identifier_uunum'=>$folge[2],
				'agendaitem_subject'=>$entry['agenda_title']<>''?$entry['agenda_title']:$entry['agenda_title2'],
				'agendaitem_addition'=>$entry['agenda_addition']
			);
		}
	}
	/*if(count($nonpublicto)>0)
	foreach ($nonpublicto as $entry) {
	}*/
	//Agenda/Tagesordnungspunkte aufrufen
	$urls=array();
	foreach($all_items_by_id as $items_by_id) {
		if(in_array($items_by_id['session_id'].' '.($items_by_id['agendaitem_public']<>''?1:0).' '.$items_by_id['agendaitem_identifier'],$agendaitem_arr)) continue;
		if($items_by_id['agendaitem_public']==1&&$items_by_id['agendaitem_url']<>'') {
			$urls[]=$items_by_id['agendaitem_url'];
		}
	}
	$result=rolling_curl($urls);
	foreach($all_items_by_id as $items_by_id) {
		if(in_array($items_by_id['session_id'].' '.($items_by_id['agendaitem_public']<>''?1:0).' '.$items_by_id['agendaitem_identifier'],$agendaitem_arr)) {writelog('Überspringe agendaitem:'.$items_by_id['agendaitem_url']."\n");continue;}
		if($items_by_id['agendaitem_public']==1&&$items_by_id['agendaitem_url']<>'') {
			
			$filename=$items_by_id['agendaitem_id'].'_'.('Ö ').$items_by_id['agendaitem_identifier'];
			$location='Sitzung/'.$session_id_['year'].'/'.$session_id_['month'].'/'.$session_id;
			$html = $result[$items_by_id['agendaitem_url']]['content'];
			save_html($html,$filename,$location);
			$content=get_content($html);
			if(!exists_images($content,'attachments/'.$location)||!exists_images(get_content(file_get_contents('allris_html/'.$location.'/'.$filename.'.html')),'attachments/'.$location)) {
				$html_overwrite=get_images($html,$content,$location);
				if($html_overwrite!==false)
				save_html($html_overwrite,$filename,$location,true);
			} else
			writelog('Überspringe images'."\n");
			
			$html = str_replace('&nbsp;', ' ',$html);
			//$items_by_id['agendaitem_content']=get_content($html);
		}
		if (!$options['simulate'])
		$db->save_rows('ris_agendaitems', $items_by_id, ['agendaitem_id','agendaitem_public','agendaitem_identifier']);
		
		if($items_by_id['agendaitem_public']==1&&$items_by_id['agendaitem_url']<>'') {
			create_multi_dir('attachments/'.'Sitzung/'.$session_id_['year'].'/'.$session_id_['month'].'/'.$session_id);
			get_attachments_html($items_by_id['agendaitem_id'], $html, 'Sitzung/'.$session_id_['year'].'/'.$session_id_['month'].'/'.$session_id.'/'.$items_by_id['agendaitem_id'],'agendaitem');
		}
	}
	
	$submission_ids=[];
	$submission_links=[];
	foreach ($publicto as $entry) {
		//Vorlagen hinzufügen
		if($entry['vorlage_id']<>'') {
			if($entry['id']<>'') {
				$dataset = array(
					'agendaitem_id'=> $entry['id'],
					'submission_id'=> $entry['vorlage_id']
				);
				if (!$options['simulate'])
					$db->save_rows('ris_agendaitems2submissions', $dataset, ['agendaitem_id', 'submission_id']);
			}
			$submission_ids[]=$entry['vorlage_id'];
			$submission_links[$entry['vorlage_id']]=array('vorlage_id'=>$entry['vorlage_id'],'vorlage_formlink'=>$entry['vorlage_formlink'],'vorlage_title'=>$entry['vorlage_title'],'vorlage_url'=>$entry['vorlage_url']);
		}
	}
	if($options['verbose'])
		writelog( "Gefundene Vorlagen (" . count($submission_links) . "): ".implode(', ',$submission_ids)."\n");

	# in Queue ablegen
	foreach ($submission_links as $submission_id)
		if(!in_array($submission_id['vorlage_id'],$submission_queue))
		$submission_queue[]=$submission_id;
}

//356
function get_document_details($dtype, $id_,$html) {
    /*
    Scrapet die Detailseite einer Vorlage (submission)
    */
	global $config,$db,$options,$submission_arr,$submission_queue;
	$id=$id_['vorlage_id'];
    if ($options['verbose'])
        writelog(sprintf( "get_document_details('%s', %d) aufgerufen" , $dtype, $id)."\n");
    if ($id == 0) {
        writelog( "Fehler: Dokumenten-ID ist 0."."\n");
        return;
	}
    $data = [];
    $prefix = '';
    if ($dtype == 'submission') {
        //$url = $config['BASEURL'] . sprintf($config['URI_SUBMISSION_DETAILS'], $id);
		$url = $id_['vorlage_url'];
        $prefix = 'submission_';
        writelog( "Lade Vorlage ". $id.' '.$url."\n");
	}
    $data[$prefix . 'id'] = $id;
	save_html($html,$id,'Vorlage/'.implode('/',array_reverse(explode('/',$id_['vorlage_title']))));
	$location='Vorlage/'.implode('/',array_reverse(explode('/',$id_['vorlage_title'])));
	$content=get_content($html);
	if(!exists_images($content,'attachments/'.$location)||!exists_images(get_content(file_get_contents('allris_html/'.$location.'/'.$id.'.html')),'attachments/'.$location)) {
		$html_overwrite=get_images($html,$content,$location);
		if($html_overwrite!==false)
		save_html($html_overwrite,$id,'Vorlage/'.implode('/',array_reverse(explode('/',$id_['vorlage_title']))),true);
	} else
	writelog('Überspringe images'."\n");
	$html = str_replace('&nbsp;', ' ',$html);
    //$html = str_replace('<br>', '; ',$html);
    $data[$prefix . 'identifier'] = cleanup_identifier_string(scrape('<div id="risname">\s*<h1>(.*?)</h1>\s*</div>', $html));
    $data[$prefix . 'formlink'] = $id_['vorlage_formlink'];
    $data[$prefix . 'subject'] = scrape('<tr>\s*<td class="kb1">Betreff:</td>\s*<td class="text1" colspan="4"> <script.*?</script>(.*?)</td> ', $html);
    $data[$prefix . 'state'] = scrape('<td class="kb1">Status:</td>\s*<td class="text3">(.*?)</td>', $html);
    $data[$prefix . 'type'] = scrape('<td class="kb1">Vorlage-Art:</td>\s*<td class="text4">(.*?)</td>', $html);
    $data[$prefix . 'leading'] = scrape('<td class="kb1">Federf&uuml;hrend:</td>\s*<td class="text4">(.*?)</td>', $html);
    $submissions_regarding=scrape_all('<table class="tk1" cellspacing="0" cellpadding="0">
                   <tbody>
                    <tr>
                     <td>
                      <form action="(?<vorlage_formlink>.*?)" method="post" style="margin:0">
                       <input type="hidden" name="VOLFDNR" value="(?<vorlage_id>.*?)" />
                       <input type="submit" class="il1_vo" value="VO" title="Vorlage" />
                      </form></td>
                     <td.*?><a href="(?<vorlage_url>.*?)">(?<vorlage_title>.*?) </a></td>
                    </tr>
                   </tbody>
                  </table>', $html);
	$regardings=[];
	foreach($submissions_regarding as $entry) {
		$regardings[]=$entry['vorlage_id'];
		if(!in_array($entry['vorlage_id'],$submission_queue))
		$submission_queue[]=array('vorlage_id'=>$entry['vorlage_id'],'vorlage_formlink'=>$entry['vorlage_formlink'],'vorlage_title'=>$entry['vorlage_title'],'vorlage_url'=>$entry['vorlage_url']);
	}
	$involved=scrape('<td class="kb1">Beteiligt:</td>\s*<td class="text4">(.*?)</td> ', $html);
	
	if($involved<>'') {
		$involved_array=scrape_all('<tr>
					 <td class="kb1"> </td>
					 <td class="text4"> </td> 
					 <td class="kb1"> </td>
					 <td class="text4">(?<involved>.*?)</td> 
					</tr>', $html);
		if(count($involved_array)>0)
			foreach($involved_array as $involved_value)
				$involved .=','.$involved_value;
	}
	$data[$prefix . 'regarding'] = implode(',',$regardings);
	$data[$prefix . 'involved'] = $involved;
	//$data[$prefix . 'content']=$content;
	

    if ($dtype == 'submission')
        if (!$options['simulate'])
            $db->save_rows('ris_submissions', $data, ['submission_id']);

    # Lade Anhänge in der Vorlage
	get_attachments_html($id, $html, $location,'submission');

    # Auslesen der bisherigen Beratungsfolge
    # Hier bekommen wir Informationen über die Verknüpfung zwischen diesem Dokument
    # und Tagesordnungspunkten, in denen es behandelt wurde (agendaitems2submissions bzw.
    # agendaitems2requests). Außerdem werden die Tagesordnungspunkte selbst angelegt.
    $top_past = scrape_all('<tr valign="top" class="zl12"> 
                     <td bgcolor=".*?" title=".*?"></td> 
                     <td class="text1" colspan="3">(?<committee_name>.*?)</td> 
                     <td class="text1" colspan="3" nowrap="nowrap">(?<type>.*?)</td> 
                    </tr> 
                    <tr valign="top" class="zl12"> 
                     <td bgcolor=".*?" title=".*?"></td> 
                     <td class="text2"><a href=".*?" title=".*?">(?<date>.*?)</a>.*?</td> 
                     <td>
                      <form action=".*?" method="post" style="margin:0">
                       <input type="hidden" name="SILFDNR" value="(?<session_id>.*?)" />
                       <input type="submit" class="il1_to" value="TO" title="Tagesordnung" />
                      </form></td> 
                     <td><a href=".*?">(?<role>.*?)</a></td> 
                     <td class="text3">(?<agendaitem_result>.*?)</td> 
                     <td> </td> 
                     <td>
                      <form action=".*?" method="post" style="margin:0">
                       <input type="hidden" name="TOLFDNR" value="(?<agendaitem_id>.*?)" />
                       <input type="submit" class="il1_naz" value="NA" title="Auszug" />
                      </form></td> 
                    </tr>', $html);
    #debug
    if ($options['verbose'])
        writelog(sprintf( "Beratungsfolge Vergangenheit: %d Eintraege: %s" , count($top_past),'')."\n");
            //json.dumps(top_past, indent=4, sort_keys=True);

    # Nachverarbeitung der Tagesordnungspunkte
    if (isset($top_past) and (count($top_past) > 0)) {
        /*$insert_tops = [];
        foreach($top_past as $top) {
            $newtop = array(
                'agendaitem_id'=> $top['agendaitem_id'],
                'session_id'=> $top['session_id'],
                'agendaitem_identifier'=> $top['agendaitem_identifier'],
                'agendaitem_result'=> $top['agendaitem_result']
            );
            $insert_tops[]=$newtop;
		}
        # Tagesordnungspunkte schreiben
        if (!$options['simulate'])
            $db->save_rows('ris_agendaitems', $insert_tops, ['agendaitem_id']);*/
        # Verknuepfungen schreiben
        //$table_name = sprintf('agendaitems2%ss' , $dtype);  # agendaitems2submissions oder agendaitems2requests
        //$docid_field_name = sprintf('%s_id' , $dtype);      # submission_id oder request_id
        foreach($top_past as $top) {
            $dataset = array(
                'agendaitem_id'=> $top['agendaitem_id'],
                'submission_id'=> $id
            );
            if (!$options['simulate'])
                $db->save_rows('ris_agendaitems2submissions', $dataset, ['agendaitem_id', 'submission_id']);
		}
	}
}

//482
function save_temp_file($data) {
    /*
    Speichert die übergebenen Daten in einer temporären Datei
    und gibt den Pfad zurück
    */
	global $config;
    $sha = sha1($data);
    if(!file_exists($config['TMP_FOLDER']))
        mkdir($config['TMP_FOLDER']);
    $path = $config['TMP_FOLDER'] . '/' . $sha;
    $f = fopen($path, 'w');
    fwrite($f,$data);
    fclose($f);
    #print "save_temp_file(): Abgelegt in", path
    return $path;
}
//523
function get_attachments($list,$pre_filename) {
    /*
    Scrapet von der Seite mit der gegebenen URL alle Dokumente, die
    über die übergebene Formular-Liste gekennzeichnet werden.
    */
	global $db,$options,$attachment_arr;
    $ret = [];
    if($options['verbose'])
        writelog( "Anzahl Anhänge zu laden: ". count($list)."\n");

	$urls=array();
	foreach($list as $value) {
		$filename=$pre_filename.'/'.str_replace(array('/',': ',':','"',''),array('_',' - ','_','-',''),$value['linktitle']);
		if(in_array($filename,$attachment_arr)) continue;
		$urls[]=$value['url'];
	}
	$result=rolling_curl($urls);
	foreach($list as $value) {
		$filename=$pre_filename.'/'.str_replace(array('/',': ',':','"',''),array('_',' - ','_','-',''),$value['linktitle']);
		if(in_array($filename,$attachment_arr)) {writelog('Überspringe attachment:'.$value['url']."\n");continue;}
		$data = $result[$value['url']];
		/*if($value['type']=='raw')
			get_images($data['content'],$pre_filename);*/

		$headers=$data['headers'];
		
		$attachment_id=$value['id'];
		$ret[$attachment_id] = array(
			'attachment_id'=> $attachment_id,
			'attachment_mimetype'=> $headers['content-type'],
			'attachment_size'=> strlen($data['content']),
			'attachment_lastmod'=> isset($headers['last-modified'])?date('Y-m-d H:i:s',strtotime($headers['last-modified'])):date('Y-m-d H:i:s'),
			'attachment_type'=> $value['type']
		);
        //$STATS['attachments_loaded'] += 1
		# Datei erst mal temporaer ablegen
		$temp_path = save_temp_file($data['content']);
		$ret[$attachment_id]['sha1_checksum'] = sha1_file($temp_path);
		if($headers['content-type']=='application/pdf')
			$doctype='pdf';
		else
			$doctype=explode(';',explode('/',$headers['content-type'])[1])[0];
		if($doctype=='jpeg') $doctype='jpg';
		if(strlen($data['content'])<=0||$doctype==''||($doctype=='html'&&strpos($data['content'],'Datei oder Verzeichnis wurde nicht gefunden.')!==false)) continue;
		# TODO: file_type mit doctype abgleichen
		# Feststellen, ob Datei schon existiert
		$folder = get_cache_path($value['linktitle']);
		$attachment_location=$pre_filename;
		$attachment_filename=str_replace(array('/',': ',':','"',''),array('_',' - ','_','-',''),$value['linktitle']). '.' . $doctype;
		$ret[$attachment_id]['attachment_location'] = $attachment_location.'/';
		$ret[$attachment_id]['attachment_filename'] = $attachment_filename;
		$full_filepath = $folder . '/' . $attachment_location . '/' . $attachment_filename;
		$overwrite = true;
		if(file_exists($full_filepath)) {
			# Datei nicht austauschen, wenn identisch
			$old_stat = $full_filepath;
			$new_stat = $temp_path;
			if(filesize($old_stat) == filesize($new_stat))
				if($ret[$attachment_id]['sha1_checksum'] == sha1_file($full_filepath))
					$overwrite = false;
					if ($options['verbose'])
						writelog( "Datei ". $full_filepath. " bleibt unverändert"."\n");
				else
					if ($options['verbose'])
						writelog( "Datei ". $full_filepath. " wird überschrieben (verschiedene Prüfsumme)"."\n");
					//$STATS['attachments_replaced'] += 1;
			else
				if ($options['verbose'])
					writelog( "Datei ". $full_filepath. " wird überschrieben (verschiedene Dateigröße)"."\n");
				//$STATS['attachments_replaced'] += 1
		}
		else {
			if ($options['verbose'])
				writelog( "Datei ". $full_filepath. " ist neu"."\n");
			//$STATS['attachments_new'] += 1
		}
		if ($overwrite) {
			# Temp-Datei an ihren endgültigen Ort bewegen
			if (!file_exists($folder))
				mkdir($folder);
			create_multi_dir($folder. '/' . $attachment_location);
			if (!file_exists($folder. '/' . $attachment_location))
				mkdir($folder. '/' . $attachment_location);
			if (!$options['simulate'])
				rename($temp_path, $full_filepath);
			else
				unlink($temp_path);
		}
		if (file_exists($temp_path))
			unlink($temp_path);
			/*if ($ret[$attachment_id]['attachment_mimetype'] == 'application/pdf') {
				# PDF-Inhalt auslesen
				$content = get_text_from_pdf($full_filepath);
				if ($content<>'')
					$ret[$attachment_id]['attachment_content'] = $content;
			}*/
			if($value['type']=='raw') continue;
			# Objekt in die Datenbank schreiben
			if (!$options['simulate']) {
				$db->execute(sprintf("DELETE FROM ris_attachments WHERE attachment_id='%s'", mysql_real_escape_string($attachment_id)));
				if ($options['verbose'])
					writelog(sprintf( "Schreibe Eintrag attachment_id=%s in Tabelle 'attachments'" , $attachment_id)."\n");
				$db->save_rows('ris_attachments', $ret[$attachment_id], ['attachment_id']);
			}
	}
    return $ret;
}

function get_attachments_html($id, $html,$pre_filename,$type) {
    # Alle Attachments finden, um Attachments außerhalb der Tagesordnung zu erfassen (Einladung, Niederschrift, Wortprotokoll)
	global $db,$options;
    $attachments_head = scrape_all('<tr valign="top"> 
                     <td class="text3"><a href="(?<url>.*?)" target="_blank".*?>(?<linktitle>.*?)</a></td> 
                     <td class="text3">.*?</td> 
                    </tr>', $html);
    $attachments_bottom = scrape_all('<tr valign="top"> 
             <td><img width="11" height="16" src=".*?" alt=".*?" title="Herunterladen durch Rechtsklick auf den Link und \'Ziel speichern unter...\'" /></td> 
             <td class="text3">.*?</td> 
             <td class="text3"><a href="(?<url>.*?)" target="_blank" title=".*?" onmouseover=".*?" onmouseout="status=\'\'; return true;">(?<linktitle>.*?)</a></td> 
             <td class="text3">.*?</td> 
             <td class="text3">.*?</td> 
             <td class="text3">.*?</td> 
            </tr>', $html);
	$html1=substr($html,strpos($html,'<td class="me1" align'));
	$html1=substr($html1,0,strpos($html1,'</td>'));
    $attachments_form = scrape_all('<form action="(?<formgeturl>.*?)" method="post" style="margin:0" target="_blank">
                   <input type="hidden" name="DOLFDNR" value="(?<id>.*?)" />
                   <input type="hidden" name="options" value="(?<options>.*?)" />
                   <input type="submit" class="il2_p" value="(?<linktitle>.*?)" title=".*?" />
                  </form>', $html1);
    if($options['verbose'])
        writelog( "Alle Anhänge: ".(count($attachments_head)+count($attachments_bottom)+count($attachments_form))."\n");
	$attachments_all = [];
	foreach($attachments_bottom as $attachment) {
		$attachment['linktitle']=trim(substr($attachment['linktitle'],0,strrpos($attachment['linktitle'],'(')));
		$attachments_all[$attachment['url']]=array('id'=>$pre_filename.'_'.$attachment['linktitle'],'url'=>$attachment['url'],'linktitle'=>$attachment['linktitle'],'type'=>'bottom');
	}
	foreach($attachments_head as $attachment) {
		$position_type=isset($attachments_all[$attachment['url']])?'headbottom':'head';
		$attachments_all[$attachment['url']]=array('id'=>$pre_filename.'_'.$attachment['linktitle'],'url'=>$attachment['url'],'linktitle'=>$attachment['linktitle'],'type'=>$position_type);
	}
	foreach($attachments_form as $attachment) {
		$attachments_all[$attachment['id']]=array('id'=>$attachment['id'],'url'=>$attachment['formgeturl'].'?DOLFDNR='.$attachment['id'].'&options='.$attachment['options'],'linktitle'=>$attachment['linktitle'],'type'=>'form');
		//,'formgeturl'=>$attachment['formgeturl'],'options'=>$attachment['options']
		$attachments_all[$attachment['id'].'raw']=array('id'=>$attachment['id'].'raw','url'=>$attachment['formgeturl'].'?DOLFDNR='.$attachment['id'],'linktitle'=>$attachment['linktitle'],'type'=>'raw');
	}
	
    $attachments_queue = [];
	foreach($attachments_all as $att) {
		$attachments_queue[]=$att;
		if($att['type']=='raw') continue;
		$dataset = array(
			$type.'_id'=> $id,
			'attachment_id'=> $att['id'],
			'attachment_role'=> $att['linktitle']
		);
		if(!$options['simulate'])
			$db->save_rows('ris_'.$type.'s2attachments', $dataset, [$type.'_id', 'attachment_id']);
	}
	if(count($attachments_queue) > 0) {
		if ($options['verbose'])
			writelog( "Attachments zum Download von ".$type."<br>\n");//, get_session_detail_url(session_id), ":", attachments_queue
            get_attachments($attachments_queue,$pre_filename);
	}
}

//622
function get_date($string) {
	/*
	Normalisiert Datumsangaben wie '1.2.2010' zu
	ISO-Schreibweise '2010-02-01'
	*/
	$result = explode('.',$string);
	$day = intval($result[0]);
	$month = intval($result[1]);
	$year = intval($result[2]);
	return sprintf("%d-%02d-%02d", $year, $month, $day);
}

//645
function get_start_end_time($string) {
	/*
	Normalisiert Anfangs- und End-Zeitangabe zu ISO-Zeit-Tupel.
	Z.B. '15 bis 16:25' => ('15:00', '16:25')
	*/
	$string = trim($string);
	if ($string == '')
		return ['', ''];
	$parts = explode(" - ",$string);
	if (strlen($parts[0]) == 2)
		$parts[0] .= ':00';
	if (!isset($parts[1]))
		array_push($parts,'');
	return [$parts[0], $parts[1]];
}

//696
function is_committee_in_db($committee_id) {
	/*Prüft, ob das Gremium mit der ID in der Datenbank vorhanden ist.*/
	global $db;
	$result = $db->get_rows(sprintf('SELECT committee_title FROM committees WHERE committee_title=%s', mysql_real_escape_string($committee_id)));
	if(count($result) > 0)
	return true;
	return false;
}

//705
function get_committee_details($title) {
	/*
	Scrapet Details zu einem Gremium
	*/
	global $db,$config;
	$url = $config['BASEURL'] . sprintf($config['URI_COMMITTEE'], intval($id));
	writelog( "Lade Gremium ".$url."\n");
	$html = file_get_content($url)['content'];
	$data = [];
	$data['committee_title'] = scrape('', $html);
	$data['committee_id'] = intval($id);
	if ($options['simulate']) {
		$db.save_rows('ris_committees', $data, ['committee_id']);
	}
}

//741
function get_cache_path($formname){
    /*
    Ermittelt anhand des Formularnamens wie "pdf12345" den Pfad
    des Ordners zum Speichern der Datei
    */
	global $config;
    $firstfolder = substr($formname,-1);     # letzte Ziffer
    $secondfolder = substr($formname,-2,1);  # vorletzte Ziffer
    $ret = ($config['ATTACHMENTFOLDER']);// . '/' . $firstfolder . '/' . $secondfolder);
    return $ret;
}
//788
function scrape_sessions($years, $months) {
	global $options,$config,$submission_queue,$session_arr;
	/*
	Mit dieser Funktion werden gezielt die Sitzungen aus einem bestimmten
	Zeitraum gescrapet. Der erste Parameter ist eine Liste mit Jahren,
	der zweite eine Liste mit Monaten (jeweils in Zahlen).
	*/
	writelog( "Scrape Jahr(e) ".implode(',',$years).", Monate ".implode(',',$months)."\n");
	foreach ($years as $year) {
		foreach ($months as $month) {
			$path='attachments/'.$year.'/'.$month;
			if(iscalendercomplete($path,$month, $year)) {writelog('Überspringe Monat:'.$month."\n");continue;}
			$submission_queue = array();//new Queue();
			$session_ids = get_session_ids_html($year, $month);
			$urls=array();
			foreach($session_ids as $session_id) {
				if(in_array($session_id['id'],$session_arr)) {writelog('Überspringe session:'.$session_id['id']."\n");continue;}
				$urls[]=$session_id['url'];
			}
			$result=rolling_curl($urls);
			foreach($session_ids as $session_id) {
				if(in_array($session_id['id'],$session_arr)) continue;
				if ($options['verbose']) {
					writelog( "Jahr ".$year.", Monat ".$month.", Session ".$session_id['id']."\n");
				}
				$html = $result[$session_id['url']]['content'];
				get_session_details_html($session_id,$html);
			}
    		scrape_from_queue();
		}
	}
}
//804
function scrape_from_queue(){
    /*
    Arbeitet die session_queue, submission_queue, request_queue ab. Damit
    werden Dokumente und Sitzungen, die zuvor in die Warteschlange gelegt wurden,
    gescrapet.
    */
	global $options,$submission_queue,$submission_arr;
	$submission_count=count($submission_queue);
	$urls=array();
	$i=0;
	if(count($submission_queue)>0)
	do {
		$submission_id=$submission_queue[$i];
		$i++;
		$urls=[];
		if ($options['verbose'])
            writelog( "Scrape Vorlage aus Warteschlange: ". $submission_id['vorlage_id']. ", verbleiben ". $submission_count."\n");
		$submission_count--;
		if(in_array($submission_id['vorlage_id'],$submission_arr)) {writelog('Überspringe submission:'.$submission_id['vorlage_url']."\n");continue;}
		$urls[]=$submission_id['vorlage_url'];
		$result=rolling_curl($urls);
 		$html = $result[$submission_id['vorlage_url']]['content'];
        get_document_details('submission', $submission_id,$html);
	} while ($i<count($submission_queue));
}
function iscalendercomplete($path,$month, $year) {
	global $session_arr;
	$html_source=str_replace('attachments/','allris_html/',$path);
	if(count(glob($html_source.'.*'))<>2) return false;
	$files=glob($html_source.'.*');
	foreach($files as $file) {
		if(!file_exists($file)||(file_exists($file)&&filesize($file)<=1400&&strpos(file_get_contents($file),'(Angefordertes Dokument nicht im Bestand)')===false)) return false;
		if(!file_error($file)) return false;
	}
	$html = file_get_contents(glob($html_source.'.html')[0]);
	$data = scrape_all('</form>\s*</td>\s*<td>\s*<form action="(?<formlink>.*?)" method="post" style="margin:0">\s*<input type="hidden" name="SILFDNR" value="(?<ksinr>\d*?)" />\s*<input type="submit" class="il1_to" value="TO" title="Tagesordnung" />\s*</form></td>\s*<td.*?><a href="(?<url>.*?)">(?<title>.*?)</a>', $html);
	foreach ($data as $key => $item) {
		if(!in_array($item['ksinr'],$session_arr)) return false;
	}
	return true;
}

function isattachmentcomplete($filename,$type) {
	$files[]=$filename;
	if($type=='form') {
		if(substr($filename,-3)<>'pdf') return false;
		$files[]=str_replace('.pdf','.html',$filename);
	}
	foreach($files as $file) {
		if(!file_exists($file)||(file_exists($file)&&filesize($file)<=1400&&strpos(file_get_contents($file),'(Angefordertes Dokument nicht im Bestand)')===false)) return false;
		if(!file_error($file)) return false;
	}
	return true;
}

function iscomplete($path,$type) {
	global $agendaitem_arr,$submission_arr;
	$html_source=str_replace('attachments/','allris_html/',$path);
	if($type=='session') {
		$session=explode('/',$path);
		$session_id=array_pop($session);
		if(!file_exists($html_source)) return false;
		$html_source.='';
		$html=file_get_contents($html_source.'.html');
		$publicto = scrape_all('<td class="text4" nowrap="nowrap">(<span style="background-color:#8ae234" title="(?<agenda_addition>.*?)">|.*?)<a href=".*?>(?<agenda_type>.*?) (?<agenda_topid>.*?)</a>(</span>|.*?)</td> 
             <td>.*?</td> 
             <td>(
              <form action="(?<agenda_formlink>.*?)" method="post" style="margin:0">
               <input type="hidden" name="TOLFDNR" value="(.*?)" />
               <input type="submit" class="il1_naz" value="NA" title="(?<agenda_result>.*?)" />
              </form>|.*?)</td> 
             <td(.*?| nowrap="nowrap")>(<a href="(?<agenda_url>.*?)">(?<agenda_title>.*?)</a>|(?<agenda_title2>.*?))</td> (
             <!--(?<id>.*?) --> |.*?)
             <td>(
              <form action="(?<vorlage_formlink>.*?)" method="post" style="margin:0">
               <input type="hidden" name="VOLFDNR" value="(?<vorlage_id>.*?)" />
               <input type="submit" class="il1_vo" value="VO" title="Vorlage" />
              </form>|.*?)</td> 
             <td(.*?| nowrap="nowrap")>(<a href="(?<vorlage_url>.*?)">(?<vorlage_title>.*?) </a>|.*?)</td> 
             <td>.*?</td>', $html);
		foreach ($publicto as $entry) {
			//if($entry['agendaitem_public']==1)
			if(!in_array($session_id.' '.($entry['agenda_type']<>'N'?1:0).' '.$entry['agenda_topid'],$agendaitem_arr)) return false;
			if($entry['vorlage_id'])
			if(!in_array($entry['vorlage_id'],$submission_arr)) return false;
		}
	} else
	if($type=='submission') {
		if(!file_exists($html_source)) return false;
		$html_source.='/';
	} else
	if($type=='agendaitem') {
		$html_source.='_';
	}
	if(count(glob($html_source.'*.*'))<>2) return false;
	$files=glob($html_source.'*.*');
	foreach($files as $file) {
		if(!file_exists($file)||(file_exists($file)&&filesize($file)<=1400&&strpos(file_get_contents($file),'(Angefordertes Dokument nicht im Bestand)')===false)) return false;
		if(!file_error($file)) return false;
	}
	$html = file_get_contents(glob($html_source.'*.html')[0]);
	if($type=='submission'||$type=='agendaitem') {
		$content=get_content($html);
		if(!exists_images($content,$path)) return false;
	}
	$html = str_replace('&nbsp;', ' ',$html);
    $attachments_head = scrape_all('<tr valign="top"> 
                     <td class="text3"><a href="(?<url>.*?)" target="_blank".*?>(?<linktitle>.*?)</a></td> 
                     <td class="text3">.*?</td> 
                    </tr>', $html);
    $attachments_bottom = scrape_all('<tr valign="top"> 
             <td><img width="11" height="16" src=".*?" alt=".*?" title="Herunterladen durch Rechtsklick auf den Link und \'Ziel speichern unter...\'" /></td> 
             <td class="text3">.*?</td> 
             <td class="text3"><a href="(?<url>.*?)" target="_blank" title=".*?" onmouseover=".*?" onmouseout="status=\'\'; return true;">(?<linktitle>.*?)</a></td> 
             <td class="text3">.*?</td> 
             <td class="text3">.*?</td> 
             <td class="text3">.*?</td> 
            </tr>', $html);
    $attachments_bottom2 = scrape_all('Herunterladen durch Rechtsklick', $html);
	$html1=substr($html,strpos($html,'<td class="me1" align'));
	$html1=substr($html1,0,strpos($html1,'</td>'));
    $attachments_form = scrape_all('<form action="(?<formgeturl>.*?)" method="post" style="margin:0" target="_blank">
                   <input type="hidden" name="DOLFDNR" value="(?<id>.*?)" />
                   <input type="hidden" name="options" value="(?<options>.*?)" />
                   <input type="submit" class="il2_p" value="(?<linktitle>.*?)" title=".*?" />
                  </form>', $html1);
    $attachments_form2 = scrape_all('name="DOLFDNR" value="(?<id>.*?)" />', $html);
	if(count($attachments_bottom)<>count($attachments_bottom2)) {
		return false;
	}
	if(count($attachments_form)<>count($attachments_form2)) {
		return false;
	}
	$attachments=[];
	foreach($attachments_bottom as $attachment) {
		$linktitle=trim(substr($attachment['linktitle'],0,strrpos($attachment['linktitle'],'(')));
		$attachments[$linktitle.'']=1;
	}
	foreach($attachments_head as $attachment) {
		$linktitle=trim($attachment['linktitle']);
		$attachments[$linktitle.'']=1;
	}
	foreach($attachments_form as $attachment) {
		$linktitle=trim($attachment['linktitle']);
		$attachments[$linktitle.'']=1;
	}
	foreach($attachments as $key => $value) {
		$file=$path.'/'.$key.'.pdf';
		if(!file_exists($file)||(file_exists($file)&&filesize($file)<=1400)) return false;
		if(!file_error($file)) return false;
	}
	return true;
}
$y=$_GET['y'];
$m=$_GET['m'];
if(isset($y)&&isset($m)) {
require($_SERVER["DOCUMENT_ROOT"]."/config/db_config.php");
$db = new DataStore($db_name, $db_host, $db_user, $db_password);
$agendaitem_arr=[];
$submission_arr=[];
$attachment_arr=[];
$session_arr=[];
/*$rows=$db->get_rows('SELECT a.session_id session_id,agendaitem_id,agendaitem_public,agendaitem_identifier,YEAR(session_date) year, MONTH(session_date) month FROM `ris_agendaitems` a LEFT JOIN `ris_sessions` b ON (a.`session_id`=b.`session_id`) WHERE YEAR(session_date)='.$y.' AND MONTH(session_date)='.$m.'');
foreach($rows as $row) {
	$path='attachments/Sitzung/'.$row['year'].'/'.$row['month'].'/'.$row['session_id'].'/'.$row['agendaitem_id'].'';
	if(iscomplete($path,'agendaitem')||$row['agendaitem_public']==0)
	$agendaitem_arr[]=$row['session_id'].' '.$row['agendaitem_public'].' '.$row['agendaitem_identifier'];
}
$rows=$db->get_rows('SELECT submission_id,submission_identifier FROM `ris_submissions` WHERE submission_identifier<>\'\' AND (`submission_identifier` LIKE \'%/'.($y-1).'%\' OR `submission_identifier` LIKE \'%/'.$y.'%\' OR `submission_identifier` LIKE \'%/'.($y+1).'%\')');// WHERE submission_regarding=\'\'
foreach($rows as $row) {
	$path='attachments/Vorlage/'.implode('/',array_reverse(explode('/',str_replace('Vorlage - ','',$row['submission_identifier']))));
	if(iscomplete($path,'submission'))
	$submission_arr[]=$row['submission_id'];
}
$rows=$db->get_rows('SELECT attachment_location,attachment_filename,attachment_type FROM `ris_attachments` WHERE attachment_size>0 AND (`attachment_location` LIKE \'%/'.($y-1).'%\' OR `attachment_location` LIKE \'%/'.$y.'%\' OR `attachment_location` LIKE \'%/'.($y+1).'%\')');
foreach($rows as $row) {
	$filename=utf8_decode('attachments/'.$row['attachment_location'].$row['attachment_filename']);
	if(isattachmentcomplete($filename,$row['attachment_type']))
	$attachment_arr[]=$row['attachment_location'].substr($row['attachment_filename'], 0, strrpos($row['attachment_filename'], '.'));
}
$rows=$db->get_rows('SELECT session_id,YEAR(session_date) year, MONTH(session_date) month FROM `ris_sessions` WHERE YEAR(session_date)='.$y.' AND MONTH(session_date)='.$m.'');
foreach($rows as $row) {
	$path='attachments/Sitzung/'.$row['year'].'/'.$row['month'].'/'.$row['session_id'].'';
	if(iscomplete($path,'session'))
	$session_arr[]=$row['session_id'];
}*/
# Queue für abzurufende Vorlagen, Anträge und Sessions
$submission_queue = array();//new Queue();
//for($y=2006;$y<=2006;$y++)
$years[]=$y;
//$years=[2014];
//for($m=1;$m<=12;$m++)
$months[]=$m;
//scrape_sessions($years, $months);

file_get_content('https://www.hagen.de/login?redirectUrl=%2Firj%2Fportal%2FAllrisB');
scrape_sessions($years, $months);
fclose($log);
$m++;
if($m>12) {
	$m=1;
	$y++;
}
if($y<=2015) {
//header("Location: scrape.php?y=".$y."&m=".$m);
	print '<html><head><meta http-equiv="refresh" content="0;scrape.php?y='.$y.'&m='.$m.'"></head><body></body></html>';
}
}
?>