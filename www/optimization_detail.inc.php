<?php
require_once('page_data.inc');
require_once('object_detail.inc');

/**
* Parse the page data and load the optimization-specific details
*
* @param mixed $pagedata
*/
function getOptimizationGrades(&$pageData, &$test, $id, $run)
{
    $opt = null;

    if( $pageData )
    {
        $opt = array();

        // put them in rank-order
        $opt['ttfb'] = array();
        $opt['keep-alive'] = array();
        $opt['gzip'] = array();
        $opt['image_compression'] = array();
        $opt['caching'] = array();
        $opt['combine'] = array();
        $opt['cdn'] = array();
        $opt['cookies'] = array();
        $opt['minify'] = array();
        $opt['e-tags'] = array();

        // get the scores
        $opt['ttfb']['score'] = gradeTTFB($pageData, $test, $id, $run, 0, $target);
        $opt['keep-alive']['score'] = $pageData['score_keep-alive'];
        $opt['gzip']['score'] = $pageData['score_gzip'];
        $opt['image_compression']['score'] = $pageData['score_compress'];
        $opt['caching']['score'] = $pageData['score_cache'];
        $opt['combine']['score'] = $pageData['score_combine'];
        $opt['cdn']['score'] = $pageData['score_cdn'];
        $opt['cookies']['score'] = $pageData['score_cookies'];
        $opt['minify']['score'] = $pageData['score_minify'];
        $opt['e-tags']['score'] = $pageData['score_etags'];
        if (array_key_exists('score_progressive_jpeg', $pageData) && $pageData['score_progressive_jpeg'] >= 0) {
          $opt['progressive_jpeg'] = array('score' => $pageData['score_progressive_jpeg'],
                                           'label' => 'Progressive JPEGs',
                                           'important' => true);
        }

        // define the labels for all  of them
        $opt['ttfb']['label'] = 'First Byte Time';
        $opt['keep-alive']['label'] = 'Keep-alive Enabled';
        $opt['gzip']['label'] = 'Compress Transfer';
        $opt['image_compression']['label'] = 'Compress Images';
        $opt['caching']['label'] = 'Cache static content';
        $opt['combine']['label'] = 'Combine js and css files';
        $opt['cdn']['label'] = 'Effective use of CDN';
        $opt['cookies']['label'] = 'No cookies on static content';
        $opt['minify']['label'] = 'Minify javascript';
        $opt['e-tags']['label'] = 'Disable E-Tags';

        // flag the important ones
        $opt['ttfb']['important'] = true;
        $opt['keep-alive']['important'] = true;
        $opt['gzip']['important'] = true;
        $opt['image_compression']['important'] = true;
        $opt['caching']['important'] = true;
        $opt['cdn']['important'] = true;

        // apply grades
        foreach( $opt as $check => &$item )
        {
            $grade = 'N/A';
            $weight = 0;
            if( $check == 'cdn' )
            {
                if( $item['score'] >= 80 )
                {
                    $item['grade'] = "<img src=\"{$GLOBALS['cdnPath']}/images/grade_check.png\" alt=\"yes\">";
                    $item['class'] = 'A';
                }
                else
                {
                    $item['grade'] = 'X';
                    $item['class'] = 'NA';
                }
            }
            else
            {
                if( isset($item['score']) )
                {
                    $weight = 100;
                    if( $item['score'] >= 90 )
                        $grade = 'A';
                    elseif( $item['score'] >= 80 )
                        $grade = 'B';
                    elseif( $item['score'] >= 70 )
                        $grade = 'C';
                    elseif( $item['score'] >= 60 )
                        $grade = 'D';
                    elseif( $item['score'] >= 0 )
                        $grade = 'F';
                    else
                        $weight = 0;
                }
                $item['grade'] = $grade;
                if( $grade == "N/A" )
                    $item['class'] = "NA";
                else
                    $item['class'] = $grade;
            }
            $item['weight'] = $weight;
        }
    }

    return $opt;
}

/**
* Generate a grade for the TTFB
*
* @param mixed $id
* @param mixed $run
*/
function gradeTTFB(&$pageData, &$test, $id, $run, $cached, &$target)
{
    $score = null;

    $ttfb = (int)$pageData['TTFB'];
    if( $ttfb )
    {
        // see if we can fast-path fail this test without loading the object data
        if( isset($test['testinfo']['latency']) )
        {
            $rtt = (int)$test['testinfo']['latency'] + 100;
            $worstCase = $rtt * 7 + 1000;  // 7 round trips including dns, socket, request and ssl + 1 second back-end
            if( $ttfb > $worstCase )
                $score = 0;
        }

        if( !isset($score) )
        {
            $target = getTargetTTFB($pageData, $test, $id, $run, $cached);
            $score = (int)min(max(100 - (($ttfb - $target) / 10), 0), 100);
        }
    }

    return $score;
}

/**
* Determine the target TTFB for the given test
*
* @param mixed $pageData
* @param mixed $test
* @param mixed $id
* @param mixed $run
*/
function getTargetTTFB(&$pageData, &$test, $id, $run, $cached)
{
    $target = NULL;

    $rtt = 0;
    if( isset($test['testinfo']['latency']) )
        $rtt = (int)$test['testinfo']['latency'];

    // load the object data (unavoidable, we need the socket connect time to the first host)
    require_once('object_detail.inc');
    $testPath = './' . GetTestPath($id);
    $secure = false;
    $haveLocations;
    $requests = getRequests($id, $testPath, $run, $cached, $secure, $haveLocations, false);
    if( count($requests) )
    {
        // figure out what the RTT is to the server (take the connect time from the first request unless it is over 3 seconds)
        if (isset($requests[0]['connect_start']) &&
            $requests[0]['connect_start'] >= 0 &&
            isset($requests[0]['connect_end']) &&
            $requests[0]['connect_end'] > $requests[0]['connect_start']) {
          $rtt = $requests[0]['connect_end'] - $requests[0]['connect_start'];
        } else {
          $connect_ms = $requests[0]['connect_ms'];
          if ($rtt > 0 && (!isset($connect_ms) || $connect_ms > 3000 || $connect_ms < 0))
            $rtt += 100;    // allow for an additional 100ms to reach the server on top of the traffic-shaped RTT
          else
            $rtt = $connect_ms;
        }
        
        // allow for a minimum of 100ms for the RTT
        $rtt = max($rtt, 100);
        
        $ssl_ms = 0;
        $i = 0;
        while (isset($requests[$i])) {
          if (isset($requests[$i]['contentType']) &&
              (stripos($requests[$i]['contentType'], 'ocsp') !== false ||
               stripos($requests[$i]['contentType'], 'crl') !== false)) {
            $i++;
          } else {
            if ($requests[$i]['is_secure'])
              $ssl_ms = $rtt;
            break;
          }
        }

        // RTT's: DNS + Socket Connect + HTTP Request + 100ms allowance
        $target = ($rtt * 3) + $ssl_ms + 100;
    }

    return $target;
}
?>
