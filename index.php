<?php
$proxys = [

];


function em_getallheaders()
{
    $headers = [];
    foreach ($_SERVER as $name => $value)
    {
        if (substr($name, 0, 5) == 'HTTP_')
        {
            $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
        }
    }
    return $headers;
}


function send($url, $method = 'GET', $params = [], $headers = [], $options = [])
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    if ($method == 'POST') {
        $options[CURLOPT_POST] = TRUE;
        $options[CURLOPT_POSTFIELDS] = $params;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt_array($ch, $options);
    $result = curl_exec($ch);
    return curl_errno($ch) ? curl_error($ch) : $result;
}

function multi($urls, $cookie, $headers = [], $options = [], $retry = 1)
{
    list($data, $n) = [[], 0];
    $proxys = $GLOBALS['proxys'];
    do {
        $ch = [];
        $mh = curl_multi_init();
        foreach ($urls as $k => $url) {
            $ch[$k] = curl_init($url);
            curl_setopt($ch[$k], CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($ch[$k], CURLOPT_TIMEOUT, 30);
            curl_setopt($ch[$k], CURLOPT_COOKIE, $cookie);
            curl_setopt($ch[$k], CURLOPT_HEADER, $headers);
            $proxy = $proxys[array_rand($proxys)];
            curl_setopt($ch[$k], CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
            curl_setopt($ch[$k], CURLOPT_PROXY, $proxy);
            curl_setopt_array($ch[$k], $options);
            curl_multi_add_handle($mh, $ch[$k]);
        }
        do {
            $mrc = curl_multi_exec($mh, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        while ($active && $mrc == CURLM_OK) {
            while (curl_multi_exec($mh, $active) === CURLM_CALL_MULTI_PERFORM) ;
            if (curl_multi_select($mh) != -1) {
                do {
                    $mrc = curl_multi_exec($mh, $active);
                } while ($mrc == CURLM_CALL_MULTI_PERFORM);
            }
        }
        foreach ($urls as $k => $url) {
            $data[$k] = curl_multi_getcontent($ch[$k]);
            if ($data[$k]) {
                curl_multi_remove_handle($mh, $ch[$k]);
                curl_close($ch[$k]);
                unset($urls[$k]);
            }
        }
        curl_multi_close($mh);
    } while ($urls && ++$n < $retry);
    return $data;
}

$url = "https://apilb.stepn.com/run/orderlist?order=2002&chain=103&refresh=true";

$h = em_getallheaders();

$cookie = $h['Cookie'];
$group = $h['Group'];
$rsqHeaders = [
    "accept:application/json",
    "accept-language:zh-CN",
    "accept-encoding:gzip",
    "cookie:{$cookie}",
    "version1" => $h['Version1'],
    "group" => $group,
    "host:apilb.stepn.com",
];

$rspHeader = [];
$rspBody = [];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_HEADER, TRUE);    //表示需要response header
curl_setopt($ch, CURLOPT_NOBODY, FALSE); //表示需要response body
curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, FALSE);
curl_setopt($ch, CURLOPT_AUTOREFERER, TRUE);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_HTTPHEADER, $rsqHeaders);

$proxy = $proxys[array_rand($proxys)];
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
//curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
//curl_setopt($ch, CURLOPT_PROXY, $proxy);

$result = curl_exec($ch);
if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == '200') {
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $rspHeader = substr($result, 0, $headerSize);
    $rspBody = substr($result, $headerSize);
    // return $body;
}
if(!$rspBody) {
    echo json_encode(['code' => 1001, 'data' => 'not response body']);
    exit;
}

$rspHeader = explode("\r\n", $rspHeader);
foreach ($rspHeader as $item) {
    if(strpos($item, ':') === false) continue;
    header($item);
}


$src = json_decode($rspBody, 1);
if($src['code'] != 0) {
    echo json_encode($src);
    exit;
}
$urls = [];
foreach ($src['data'] as $key => $item) {
    $urls[] = "https://apilb.stepn.com/run/orderdata?orderId={$item['id']}";
}

$rsqHeaders = [
    "user-agent:Dart/2.16 (dart:io)",
    "accept:application/json",
    "accept-language:zh-CN",
    "accept-encoding:gzip",
    "cookie:{$cookie}",
    "version1" => $h['Version1'],
    "group" => $group,
    "host:apilb.stepn.com",
];
//$urls = array_slice($urls, 0, 5);
$vals = [];
foreach ($urls as $url) {
    $vals[] = send($url, 'GET', null, $rsqHeaders);
}
$resultDict = [];

foreach ($vals as $key => $val) {
    $val = json_decode($val, 1);
    $value = 0;
    if($val['code'] == 0 && isset($val['data']["attrs"])) {
        $attrs = $val['data']["attrs"];
        $eff = $attrs[0];
        $comfort = $attrs[2];
        $resi = $attrs[3];
        # 计算 r + 弹力
        $value = $eff + $resi;
    }
    $resultDict[$key] = $value;
}
//print_r($resultDict);exit;
rsort($resultDict);
$returnData = [];
foreach ($resultDict as $key => $val) {
    $returnData[] = $src['data'][$key];
}
echo json_encode(['code' => 0, 'data' => $returnData]);exit;
$data = [
    "code" => 0,
    "data" => [
        [
            "id" => 56875516,
            "otd" => 575927719,
            "time" => 0,
            "propID" => 168250602581,
            "img" => "11/16/m218706_881dff5734764064ffdcb01e888cfe1fe422_67.png",
            "dataID" => 100102,
            "sellPrice" => 5990000,
            "hp" => 100,
            "level" => 5,
            "quality" => 1,
            "mint" => 2,
            "addRatio" => 40,
            "v1" => 246,
            "v2" => 86
        ], 
        // [
        //     "id" => 56836425,
        //     "otd" => 627444751,
        //     "time" => 0,
        //     "propID" => 109273440857,
        //     "img" => "26/8/m21870b_c921ff9d88ff888817ffd93a5d27862b7a7a_67.png",
        //     "dataID" => 100107,
        //     "sellPrice" => 6000000,
        //     "hp" => 100,
        //     "level" => 5,
        //     "quality" => 1,
        //     "mint" => 3,
        //     "addRatio" => 40,
        //     "v1" => 41,
        //     "v2" => 84
        // ], [
        //     "id" => 56841555,
        //     "otd" => 526997539,
        //     "time" => 0,
        //     "propID" => 163943528137,
        //     "img" => "15/19/m2186f3_8299cce2cc1bae5288a7884e4eff80674dff_67.png",
        //     "dataID" => 100083,
        //     "sellPrice" => 6000000,
        //     "hp" => 100,
        //     "level" => 5,
        //     "quality" => 1,
        //     "mint" => 2,
        //     "addRatio" => 40,
        //     "v1" => 35,
        //     "v2" => 44
        // ], [
        //     "id" => 56842403,
        //     "otd" => 700949181,
        //     "time" => 0,
        //     "propID" => 155175050911,
        //     "img" => "26/22/m2186f8_eb66ff46d4ffde67ff1c83ff40cd2fde4324_67.png",
        //     "dataID" => 100088,
        //     "sellPrice" => 6000000,
        //     "hp" => 100,
        //     "level" => 5,
        //     "quality" => 1,
        //     "mint" => 3,
        //     "addRatio" => 40,
        //     "v1" => 179,
        //     "v2" => 44
        // ], [
        //     "id" => 56842818,
        //     "otd" => 463017343,
        //     "time" => 0,
        //     "propID" => 153162991557,
        //     "img" => "32/14/m21870b_5afbff39888e8951cf6463090b9444289bce_67.png",
        //     "dataID" => 100107,
        //     "sellPrice" => 6000000,
        //     "hp" => 100,
        //     "level" => 5,
        //     "quality" => 1,
        //     "mint" => 2,
        //     "addRatio" => 40,
        //     "v1" => 50,
        //     "v2" => 74
        // ], [
        //     "id" => 56854261,
        //     "otd" => 284585498,
        //     "time" => 0,
        //     "propID" => 36526515919,
        //     "img" => "45/28/m2186ee_ff1689fffd8621ffffe82bff1e3188f9f0ff_67.png",
        //     "dataID" => 100078,
        //     "sellPrice" => 6000000,
        //     "hp" => 100,
        //     "level" => 5,
        //     "quality" => 1,
        //     "mint" => 3,
        //     "addRatio" => 40,
        //     "v1" => 37,
        //     "v2" => 75
        // ], [
        //     "id" => 56855507,
        //     "otd" => 894355949,
        //     "time" => 0,
        //     "propID" => 23399097235,
        //     "img" => "12/24/m2186d6_9fd488ca8f3f0c46ffffd11155d09b675f10_67.png",
        //     "dataID" => 100054,
        //     "sellPrice" => 6000000,
        //     "hp" => 100,
        //     "level" => 5,
        //     "quality" => 1,
        //     "mint" => 3,
        //     "addRatio" => 40,
        //     "v1" => 35,
        //     "v2" => 61
        // ], [
        //     "id" => 56855750,
        //     "otd" => 278434531,
        //     "time" => 0,
        //     "propID" => 131559084745,
        //     "img" => "45/19/m21870b_220b718863ff14fc6a5405813aaa16963288_67.png",
        //     "dataID" => 100107,
        //     "sellPrice" => 6000000,
        //     "hp" => 100,
        //     "level" => 5,
        //     "quality" => 1,
        //     "mint" => 2,
        //     "addRatio" => 40,
        //     "v1" => 80,
        //     "v2" => 49
        // ], [
        //     "id" => 56855796,
        //     "otd" => 971422988,
        //     "time" => 0,
        //     "propID" => 56755635079,
        //     "img" => "18/41/m2186d6_44d17cb1dcf6c7554b7c8807bd9d9d5d8dff_67.png",
        //     "dataID" => 100054,
        //     "sellPrice" => 6000000,
        //     "hp" => 100,
        //     "level" => 5,
        //     "quality" => 1,
        //     "mint" => 2,
        //     "addRatio" => 40,
        //     "v1" => 21,
        //     "v2" => 49
        // ], [
        //     "id" => 56857546,
        //     "otd" => 698287167,
        //     "time" => 0,
        //     "propID" => 130028001275,
        //     "img" => "47/50/m21870b_c7554bbd9d9dbf87e47c88075d8dff88177f_67.png",
        //     "dataID" => 100107,
        //     "sellPrice" => 6000000,
        //     "hp" => 100,
        //     "level" => 5,
        //     "quality" => 1,
        //     "mint" => 2,
        //     "addRatio" => 40,
        //     "v1" => 38,
        //     "v2" => 22
        // ], [
        //     "id" => 56859999,
        //     "otd" => 585407093,
        //     "time" => 0,
        //     "propID" => 111209673439,
        //     "img" => "40/39/m2186fd_7fc6d0eede828ea487de67ffaf472e46d4ff_67.png",
        //     "dataID" => 100093,
        //     "sellPrice" => 6000000,
        //     "hp" => 100,
        //     "level" => 5,
        //     "quality" => 1,
        //     "mint" => 3,
        //     "addRatio" => 40,
        //     "v1" => 191,
        //     "v2" => 99
        // ], [
        //     "id" => 56860458,
        //     "otd" => 589445065,
        //     "time" => 0,
        //     "propID" => 100235680609,
        //     "img" => "37/36/186d1_cfe.png",
        //     "dataID" => 100049,
        //     "sellPrice" => 6000000,
        //     "hp" => 100,
        //     "level" => 5,
        //     "quality" => 1,
        //     "mint" => 2,
        //     "addRatio" => 40,
        //     "v1" => 203,
        //     "v2" => 46
        // ], [
        //     "id" => 56860897,
        //     "otd" => 650376851,
        //     "time" => 0,
        //     "propID" => 50644613133,
        //     "img" => "14/28/m218710_3240d01e51868855ffa14c08961788881fef_67.png",
        //     "dataID" => 100112,
        //     "sellPrice" => 6000000,
        //     "hp" => 100,
        //     "level" => 5,
        //     "quality" => 1,
        //     "mint" => 2,
        //     "addRatio" => 40,
        //     "v1" => 237,
        //     "v2" => 30
        // ], [
        //     "id" => 56861475,
        //     "otd" => 188823644,
        //     "time" => 0,
        //     "propID" => 86632542813,
        //     "img" => "33/23/m2186db_4eff80639c9c674dffbe1588a7884e1d6cff_67.png",
        //     "dataID" => 100059,
        //     "sellPrice" => 6000000,
        //     "hp" => 100,
        //     "level" => 5,
        //     "quality" => 1,
        //     "mint" => 2,
        //     "addRatio" => 40,
        //     "v1" => 273,
        //     "v2" => 30
        // ], [
        //     "id" => 56865492,
        //     "otd" => 114687823,
        //     "time" => 0,
        //     "propID" => 135396719895,
        //     "img" => "41/22/m218715_fdf4e31fe4224064ff88a69e881dff888cfe_67.png",
        //     "dataID" => 100117,
        //     "sellPrice" => 6000000,
        //     "hp" => 100,
        //     "level" => 5,
        //     "quality" => 1,
        //     "mint" => 2,
        //     "addRatio" => 40,
        //     "v1" => 192,
        //     "v2" => 93
        // ], [
        //     "id" => 56867815,
        //     "otd" => 654649207,
        //     "time" => 0,
        //     "propID" => 192745432685,
        //     "img" => "14/14/m2186db_8188ff220b718863ff5405814943d2963288_67.png",
        //     "dataID" => 100059,
        //     "sellPrice" => 6000000,
        //     "hp" => 100,
        //     "level" => 5,
        //     "quality" => 1,
        //     "mint" => 2,
        //     "addRatio" => 40,
        //     "v1" => 33,
        //     "v2" => 94
        // ], [
        //     "id" => 56869674,
        //     "otd" => 729737693,
        //     "time" => 0,
        //     "propID" => 49658430763,
        //     "img" => "12/19/m218706_4bbcf532641b78d2ff09936dfa0035196a50_67.png",
        //     "dataID" => 100102,
        //     "sellPrice" => 6000000,
        //     "hp" => 100,
        //     "level" => 5,
        //     "quality" => 1,
        //     "mint" => 2,
        //     "addRatio" => 40,
        //     "v1" => 11,
        //     "v2" => 85
        // ], [
        //     "id" => 56871549,
        //     "otd" => 626251609,
        //     "time" => 0,
        //     "propID" => 73222835427,
        //     "img" => "33/39/m2186d1_299b089b4907816cb888a7b48890ff88d57f_67.png",
        //     "dataID" => 100049,
        //     "sellPrice" => 6000000,
        //     "hp" => 100,
        //     "level" => 5,
        //     "quality" => 1,
        //     "mint" => 2,
        //     "addRatio" => 40,
        //     "v1" => 215,
        //     "v2" => 17
        // ], [
        //     "id" => 56871918,
        //     "otd" => 180582203,
        //     "time" => 0,
        //     "propID" => 141375130919,
        //     "img" => "27/21/m218706_96cdc3a478f7d840ff50884bff058b883aff_67.png",
        //     "dataID" => 100102,
        //     "sellPrice" => 6000000,
        //     "hp" => 100,
        //     "level" => 5,
        //     "quality" => 1,
        //     "mint" => 2,
        //     "addRatio" => 40,
        //     "v1" => 51,
        //     "v2" => 60
        // ], [
        //     "id" => 56872116,
        //     "otd" => 360670756,
        //     "time" => 0,
        //     "propID" => 88081907721,
        //     "img" => "9/9/m218715_1c83ff4627628ea487eb66ff7fc6d0af472e_67.png",
        //     "dataID" => 100117,
        //     "sellPrice" => 6000000,
        //     "hp" => 100,
        //     "level" => 5,
        //     "quality" => 1,
        //     "mint" => 2,
        //     "addRatio" => 40,
        //     "v1" => 51,
        //     "v2" => 35
        // ], [
        //     "id" => 56873233,
        //     "otd" => 978248380,
        //     "time" => 0,
        //     "propID" => 51189083729,
        //     "img" => "26/35/m2186ee_aec02e8855ffa67b9d979504881fefa14c08_67.png",
        //     "dataID" => 100078,
        //     "sellPrice" => 6000000,
        //     "hp" => 100,
        //     "level" => 5,
        //     "quality" => 1,
        //     "mint" => 2,
        //     "addRatio" => 40,
        //     "v1" => 51,
        //     "v2" => 62
        // ]
    ]
];
$rspHeader = 'HTTP/1.1 200 OK
Accept-Ranges: bytes
Cache-Control: no-cache
Connection: keep-alive
Content-Length: 9508
Content-Type: text/html
Date: Sat, 11 Jun 2022 04:49:39 GMT
P3p: CP=" OTI DSP COR IVA OUR IND COM "
P3p: CP=" OTI DSP COR IVA OUR IND COM "
Pragma: no-cache
Server: BWS/1.1
Set-Cookie: BAIDUID=D2BD9D019829474F159F0DA45A26D844:FG=1; expires=Thu, 31-Dec-37 23:55:55 GMT; max-age=2147483647; path=/; domain=.baidu.com
Set-Cookie: BIDUPSID=D2BD9D019829474F159F0DA45A26D844; expires=Thu, 31-Dec-37 23:55:55 GMT; max-age=2147483647; path=/; domain=.baidu.com
Set-Cookie: PSTM=1654922979; expires=Thu, 31-Dec-37 23:55:55 GMT; max-age=2147483647; path=/; domain=.baidu.com
Set-Cookie: BAIDUID=D2BD9D019829474FEB3C6DA033753F64:FG=1; max-age=31536000; expires=Sun, 11-Jun-23 04:49:39 GMT; domain=.baidu.com; path=/; version=1; comment=bd
Traceid: 1654922979043422874611235226401018251347
Vary: Accept-Encoding
X-Frame-Options: sameorigin
X-Ua-Compatible: IE=Edge,chrome=1';
$rspHeader = explode("\r\n", $rspHeader);
foreach ($rspHeader as $item) {
    if(strpos($item, ':') === false) continue;
    header($item);
}
echo json_encode($data);