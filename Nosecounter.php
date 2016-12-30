<?php
/**
 * Compiles registration statistics retrieved via a JSON API and generates locally stored SVG files for caching.
 *
 * (c) 2016 by Dominik "Fenrikur" Schöner <nosecounter@fenrikur.de>
 */

namespace nosecounter;

//TODO: Add CSS-based tooltips
require_once 'SVGGraph/SVGGraph.php';

//TODO: Add theme-based backgrounds and styling (maybe additional classes required on some elements -> setting: svg_class?)
//TODO: Increase readability of overlapping elements in scatterplot

class Nosecounter {
    const NOT_AVAILABLE = 'n/a';

    /** @var string $apiUrl URL pointing to the API endpoint delivering the current stats */
    private  $apiUrl;

    /** @var string $token Token for authentication with the API */
    private $apiToken;

    /** @var int $year Year of the convention for which to generate graphics */
    private $year;

    /** @var string $archive Path to the folder containing historic data from previous years */
    private $archiveDir = './archive/';

    private $svgDir = './svg/';

    /** @var int $maxYearCount Maximum number of years (subject to availability) to be shown in comparison views */
    private $maxYearCount = 5;

    /** @var string $registrationsTimestampFormat DateTime-compatible format with which timestamps for the Created field can be generated */
    private $registrationsTimestampFormat = 'Y-m-d\TH:i:s.000O';

    /** @var \DateTime $registrationsStart At which point in time should the Registrations graph be generated? */
    private $registrationsStart;

    /** @var \DateTime $regsPerMinuteStart Until which point in time should the Registrations graph be generated? */
    private $registrationsEnd;

    /** @var int $regsPerMinuteAggregate Interval in seconds over which registrations should be aggregated */
    private $registrationsInterval = 60 * 60;

    /** @var int $topCountryCount Number of top countries displayed in comparison view */
    private $topCountryCount = 10;

    /** @var int $minAge Minimum age required to attend the convention as full (counting) attendee */
    private $minAge = 18;

    /** @var array $genderList List of all possible genders to be selected at registration */
    private $genderList = array('male', 'female');

    /** @var array $sponsorList List of all possible sponsor levels */
    private $sponsorList = array('normal', 'sponsor', 'supersponsor');

    /** @var array $specialInterestList List of all possible special interests selectable at registration */
    private $specialInterestList = array('animator', 'artist', 'fursuiter', 'musician');

    /** @var array $shirtSizeList List of all possible shirt sizes selectable at registration */
    private $shirtSizeList = array('XS', 'S', 'M', 'L', 'XL', 'XXL');

    private $topCountryList;

    private $svgGraphDefaultSettings = array(
        'back_colour' => '#eee', 'stroke_colour' => '#000',
        'back_stroke_width' => 0, 'back_stroke_colour' => '#eee',
        'axis_colour' => '#333', 'axis_overlap' => 2,
        'axis_font' => 'Georgia', 'axis_font_size' => 10,
        'grid_colour' => '#666', 'label_colour' => '#efefef',
        'pad_right' => 20, 'pad_left' => 20,
        'link_base' => '/', 'link_target' => '_top',
        'minimum_grid_spacing' => 20, 'semantic_classes' => TRUE,
        'legend_shadow_opacity' => 0, 'legend_position' => 'outside right 5 0',
        'auto_fit' => TRUE
    );

    private $graphWidth = 1000;
    private $graphHeight = 500;

    /** @var array Holds raw data collected from API and archived files. */
    private $data = array();

    /** @var \DateTimeImmutable $now */
    private $now;

    private $doRegistrations;


    /**
     * Converts percentage values from decimal (0.0 … 1.0) to textual representation.
     * Note: To be used with \SVGGraph's axis_text_callback_y.
     * @param float $value Decimal representation of percentage
     * @param int $precision Precision of percentage
     * @return string Textual representation with unit sign (0% … 100%)
     */
    private $axisPercentage;
    private $labelClosure;
    private $genderLabel;
    private $sponsorLabel;
    private $shirtSizeLabel;

    function __construct() {

        $this->axisPercentage = function($value, $precision = 0) {
            return round($value * 100, $precision) . "%";
        };

        $this->labelClosure = function($dataset, $key, $value) {

            //TODO: Nasty hack to get the year number back
            $year = substr($key, count($key) - 6, 4);

            $field = (isset($this->fieldList[$dataset])) ? $this->fieldList[$dataset] : Nosecounter::NOT_AVAILABLE;

            $absoluteValue = @$this->data[$year][$this->fieldName][$field];

            return round($value * 100, 1) . "% ({$absoluteValue})";
        };

        $this->genderLabel = \Closure::bind($this->labelClosure, (object) ['fieldName' => 'Gender', 'fieldList' => $this->genderList, 'data' => &$this->data]);
        $this->sponsorLabel = \Closure::bind($this->labelClosure, (object) ['fieldName' => 'Sponsor', 'fieldList' => $this->sponsorList, 'data' => &$this->data]);
        $this->shirtSizeLabel = \Closure::bind($this->labelClosure, (object) ['fieldName' => 'ShirtSize', 'fieldList' => $this->shirtSizeList, 'data' => &$this->data]);
    }

    public function generate() {
        $startTime = microtime(TRUE);
        $nosecounterData = array();
        if(!is_dir('./svg')) {
            mkdir('./svg');
        }
        $this->now = new \DateTimeImmutable("now", new \DateTimeZone('UTC'));
        $this->loadData();
        $nosecounterData['year'] = $this->year;
        $nosecounterData['registrationsInterval'] = round($this->registrationsInterval / 60) . ' Minutes';
        $nosecounterData['age'] = $this->generateAge();
        $nosecounterData['ageComparison'] = $this->generateAgeComparison();
        $nosecounterData['country'] = $this->generateCountry();
        $nosecounterData['countryComparison'] = $this->generateCountryComparison();
        $nosecounterData['demographics'] = $this->generateDemographics();
        $nosecounterData['gender'] = $this->generateGender();
        $nosecounterData['genderComparison'] = $this->generateGenderComparison();
        $nosecounterData['registrations'] = $this->generateRegistrations();
        $nosecounterData['shirts'] = $this->generateShirts();
        $nosecounterData['sponsors'] = $this->generateSponsors();
        $nosecounterData['sponsorsComparison'] = $this->generateSponsorsComparison();
        $nosecounterData['status'] = $this->generateStatus();
        $nosecounterData['statusbar'] = $this->generateStatusBar();
        $nosecounterData['generated'] = '<p>Generated in ' . round((microtime(true) - $startTime) * 1000, 4) . ' ms.</p>';

        return (object) $nosecounterData;
    }

    private function loadData() {
        foreach (scandir($this->archiveDir) as $file) {
            $filePath = "$this->archiveDir/$file";
            if (is_file($filePath) && ($fileJson = file_get_contents($filePath)) !== FALSE) {
                $fileData = json_decode($fileJson, true);
                $this->data[$fileData['Year']] = $fileData;
            }
        }

        $this->doRegistrations = ($this->now >= $this->registrationsStart && $this->now <= $this->registrationsEnd) || !file_exists('./svg/registrations.svg');
        if (($apiJson = file_get_contents("$this->apiUrl?token=$this->apiToken&year=$this->year".(($this->doRegistrations)?'&show-created=1':''))) !== FALSE) {
            $this->data[$this->year] = json_decode($apiJson, true);
        }

        ksort($this->data);
        $this->data = array_slice($this->data, max(0, count($this->data) - $this->maxYearCount), $this->maxYearCount, TRUE);

        foreach($this->data as $year => $yearData) {
            asort($this->data[$year]['Country']);
            $this->data[$year]['Country'] = array_reverse($this->data[$year]['Country']);
        }
    }

    private function generateAge() {
        $settings = array('grid_division_h' => 1, 'axis_text_angle_h' => -90,
            'axis_min_h' => $this->minAge);

        return $this->generateBarGraph('Age', $settings, 'age');
    }

    private function generateAgeComparison() {
        $settings = array_merge($this->svgGraphDefaultSettings,
            array('grid_division_h' => 1, 'axis_text_angle_h' => -90,
                'axis_min_h' => $this->minAge, 'legend_shadow_opacity' => 0,
                'legend_position' => 'outside right 5 0', 'legend_columns' => 1,
                'marker_type' => 'fourstar', 'marker_size' => 5,
                'pad_right' => 120));
        $values = array();

        $settings['legend_entries'] = array();

        foreach ($this->data as $year => $yearData) {
            array_push($settings['legend_entries'], "{$yearData['Convention']} ({$yearData['Year']})");
            foreach ($yearData['Age'] as $age => $ageCount) {
                $values["{$yearData['Convention']} ({$yearData['Year']})"][$age] = $ageCount;
            }
        }

        $graph = new \SVGGraph($this->graphWidth, $this->graphHeight, $settings);

        $graph->Values($values);
        return $this->writeSvg('ageComparison', $graph->Fetch('MultiScatterGraph'));
    }

    private function generateCountry() {
        $settings = array();

        return $this->generateBarGraph('Country', $settings, 'country');
    }

    private function generateCountryComparison() {
        $settings = array('legend_columns' => 2, 'pad_right' => 120);

        $this->topCountryList = array_keys(array_slice($this->data[$this->year]['Country'], 0, $this->topCountryCount));

        return $this->generateGroupedComparison('Country', $settings, 'countryComparison', $this->topCountryList);
    }

    private function generateDemographics() {
        $settings = array('pad_right' => 120);

        return $this->generateGroupedComparison('SpecialInterest', $settings, 'demographics', $this->specialInterestList);
    }

    private function generateGender() {
        $settings = array();

        return $this->generatePieGraph('Gender', $settings, 'gender');
    }

    private function generateGenderComparison() {
        $settings = array('data_label_callback' => $this->genderLabel, 'pad_right' => 100);

        return $this->generateStackedComparison('Gender', $settings, 'genderComparison', $this->genderList);
    }

    private function generateRegistrations() {
        if(!$this->doRegistrations) {
            return;
        }
        $settings = array_merge($this->svgGraphDefaultSettings,
            array('fill_under' => TRUE, 'datetime_keys' => TRUE,
            'axis_text_angle_h' => -90, 'marker_size' => 1,
            'line_stroke_width' => 0.5));
        $values = array();
        $aggregateInterval = new \DateInterval("PT{$this->registrationsInterval}S");
        $aggregatedCount = 0;
        $lastDate = null;

        $values_raw = $this->data[$this->year]['Created'];

        foreach ($values_raw as $date => $value) {
            $dateTime = new \DateTimeImmutable($date, new \DateTimeZone('UTC'));

            if($dateTime >= $this->registrationsStart && $dateTime <= $this->registrationsEnd) {
                if(!isset($aggregateStart)) {
                    $aggregateStart = $this->alignToInterval($dateTime, $this->registrationsInterval);
                }

                if($dateTime->sub($aggregateInterval) >= $aggregateStart) {

                    // Cap intervals with zero values if there is a gap between them
                    if(!array_key_exists($aggregateStart->format($this->registrationsTimestampFormat), $values)) {
                        // Check if the gap spans more than a single interval and cap previous interval if necessary
                        if(isset($lastInterval) && $aggregateStart >= $lastInterval) {
                            $values[$lastInterval->add($aggregateInterval)->format($this->registrationsTimestampFormat)] = 0;
                        }

                        // Cap new interval
                        $values[$aggregateStart->format($this->registrationsTimestampFormat)] = 0;
                    }

                    // Store aggregated value for current interval
                    $lastInterval = $aggregateStart->add($aggregateInterval);
                    $values[$lastInterval->format($this->registrationsTimestampFormat)] = $aggregatedCount;

                    // Reset aggregation for new interval
                    $aggregatedCount = $value;
                    $aggregateStart = $this->alignToInterval($dateTime, $this->registrationsInterval);
                } else {
                    $aggregatedCount += $value;
                }
                $lastDate = $dateTime;
            }
        }

        if(isset($lastDate)) {
            if(!isset($aggregateStart)) {
                $aggregateStart = $this->alignToInterval($lastDate, $this->registrationsInterval);
            }

            // Cap intervals with zero values if there is a gap between them
            if(!array_key_exists($aggregateStart->format($this->registrationsTimestampFormat), $values)) {
                // Check if the gap spans more than a single interval and cap previous interval if necessary
                if(isset($lastInterval) && $aggregateStart >= $lastInterval) {
                    $values[$lastInterval->add($aggregateInterval)->format($this->registrationsTimestampFormat)] = 0;
                }

                // Cap new interval
                $values[$aggregateStart->format($this->registrationsTimestampFormat)] = 0;
            }

            $values[$lastDate->format($this->registrationsTimestampFormat)] = $aggregatedCount;

        }

        $graph = new \SVGGraph($this->graphWidth, $this->graphHeight, $settings);
        $graph->colours = array(array('red:0.5', 'yellow:0.5'));
        $graph->Values($values);
        return $this->writeSvg('registrations', $graph->Fetch('LineGraph'));
    }

    private function generateShirts() {
        $settings = array('legend_columns' => 2, 'data_label_callback' => $this->shirtSizeLabel,
            'pad_right' => 130);

        return $this->generateStackedComparison('ShirtSize', $settings, 'shirts', $this->shirtSizeList);
    }

    private function generateSponsors() {
        $settings = array();

        return $this->generatePieGraph('Sponsor', $settings, 'sponsors');
    }

    private function generateSponsorsComparison() {
        $settings = array('data_label_callback' => $this->sponsorLabel, 'pad_right' => 140);

        return $this->generateStackedComparison('Sponsor', $settings, 'sponsorsComparison', $this->sponsorList);
    }

    private function generateStatus() {
        $settings = array();

        return $this->generateBarGraph('Status', $settings, 'status');
    }

    private function generateStatusBar() {
        $statusBar = '|';
        foreach ($this->data[$this->year]['Status'] as $status => $count) {
            $statusBar .= " $status: $count |";
        }

        return $statusBar;
    }

    private function generateBarGraph($fieldName, $settings, $fileName) {
        $settings = array_merge($this->svgGraphDefaultSettings, $settings);
        $values = $this->data[$this->year][$fieldName];

        $graph = new \SVGGraph($this->graphWidth, $this->graphHeight, $settings);

        $graph->Values($values);
        return $this->writeSvg($fileName, $graph->Fetch('BarGraph'));
    }

    private function generateGroupedComparison($fieldName, $settings, $fileName, $legendEntries) {
        $settings = array_merge($this->svgGraphDefaultSettings, $settings);
        $settings['legend_entries'] = $legendEntries;

        foreach ($this->data as $year => $yearData) {
            foreach ($legendEntries as $entry) {
                $values[$entry]["{$yearData['Convention']} ({$yearData['Year']})"] = @$yearData[$fieldName][$entry];
            }
        }

        $graph = new \SVGGraph($this->graphWidth, $this->graphHeight, $settings);
        $graph->Values($values);
        return $this->writeSvg($fileName, $graph->Fetch('GroupedBarGraph'));
    }

    private function generatePieGraph($fieldName, $settings, $fileName) {
        //TODO: Label callback to show percentage as well as absolute count
        $settings = array_merge($this->svgGraphDefaultSettings, array('show_label_amount' => TRUE), $settings);
        $values = $this->data[$this->year][$fieldName];

        $totalCount = 0;
        foreach ($values as $value) {
            $totalCount += $value;
        }
        $delta = $this->data[$this->year]['TotalCount'] - $totalCount;
        if ($delta > 0) {
            $this->data[$this->year][$fieldName][Nosecounter::NOT_AVAILABLE] = $delta;
            $values[Nosecounter::NOT_AVAILABLE] = $delta;
        }

        $graph = new \SVGGraph($this->graphWidth, $this->graphHeight, $settings);

        $graph->Values($values);
        return $this->writeSvg($fileName, $graph->Fetch('ExplodedPieGraph'));
    }

    private function generateStackedComparison($fieldName, $settings, $fileName, $legendEntries) {
        $settings = array_merge($this->svgGraphDefaultSettings, array('show_data_labels' => TRUE, 'data_label_position' => 'top',
            'data_label_colour' => '#efefef', 'axis_text_callback_y' => $this->axisPercentage), $settings);
        $values = array();
        array_pad($values, count($legendEntries), array());

        $hasUnknown = FALSE;
        foreach ($this->data as $year => $yearData) {
            $yearCount = 0;
            foreach ($legendEntries as $entry) {
                $value = isset($yearData[$fieldName][$entry]) ? $yearData[$fieldName][$entry] : 0;
                $yearCount += $value;
                $values[$entry]["{$yearData['Convention']} ({$yearData['Year']})"] = $value / $yearData['TotalCount'];
            }

            $yearUnknown = $yearData['TotalCount'] - $yearCount;
            if ($yearUnknown > 0) {
                $this->data[$year][$fieldName][Nosecounter::NOT_AVAILABLE] = $yearUnknown;
                $values[Nosecounter::NOT_AVAILABLE]["{$yearData['Convention']} ({$yearData['Year']})"] = $yearUnknown / $yearData['TotalCount'];
                $hasUnknown = TRUE;
            }
        }

        if (!$hasUnknown) {
            $settings['legend_entries'] = $legendEntries;
        } else {
            $settings['legend_entries'] = array_merge($legendEntries, array(Nosecounter::NOT_AVAILABLE));
        }

        $graph = new \SVGGraph($this->graphWidth, $this->graphHeight, $settings);

        $graph->Values($values);
        return $this->writeSvg($fileName, $graph->Fetch('StackedBarGraph'));
    }

    private function writeSvg($fileName, $svg) {
        $filePath = "{$this->svgDir}{$fileName}.svg";
        $file = fopen($filePath, 'w');
        fwrite($file, $svg);
        fclose($file);
        return "{$filePath}?t={$this->now->getTimestamp()}";
    }

    /**
     * @param \DateTimeImmutable $dateTime
     * @param $interval
     * @return mixed
     */
    private function alignToInterval(\DateTimeImmutable $dateTime, $interval) {
        return $dateTime->sub(new \DateInterval('PT' . $dateTime->getTimestamp() % $interval . 'S'));
    }

    /**
     * @return string
     */
    public function getApiUrl() {
        return $this->apiUrl;
    }

    /**
     * @param string $apiUrl
     * @return Nosecounter
     */
    public function setApiUrl($apiUrl) {
        $this->apiUrl = $apiUrl;
        return $this;
    }

    /**
     * @return string
     */
    public function getApiToken() {
        return $this->apiToken;
    }

    /**
     * @param string $apiToken
     * @return Nosecounter
     */
    public function setApiToken($apiToken) {
        $this->apiToken = $apiToken;
        return $this;
    }

    /**
     * @return int
     */
    public function getYear() {
        return $this->year;
    }

    /**
     * @param int $year
     * @return Nosecounter
     */
    public function setYear($year) {
        $this->year = $year;
        return $this;
    }

    /**
     * @return string
     */
    public function getArchiveDir() {
        return $this->archiveDir;
    }

    /**
     * @param string $archiveDir
     * @return Nosecounter
     */
    public function setArchiveDir($archiveDir) {
        $this->archiveDir = $archiveDir;
        return $this;
    }

    /**
     * @return int
     */
    public function getMaxYearCount() {
        return $this->maxYearCount;
    }

    /**
     * @param int $maxYearCount
     * @return Nosecounter
     */
    public function setMaxYearCount($maxYearCount) {
        $this->maxYearCount = $maxYearCount;
        return $this;
    }

    /**
     * @return string
     */
    public function getRegistrationsTimestampFormat() {
        return $this->registrationsTimestampFormat;
    }

    /**
     * @param string $registrationsTimestampFormat
     * @return Nosecounter
     */
    public function setRegistrationsTimestampFormat($registrationsTimestampFormat) {
        $this->registrationsTimestampFormat = $registrationsTimestampFormat;
        return $this;
    }

    /**
     * @return \DateTime
     */
    public function getRegistrationsStart() {
        return $this->registrationsStart;
    }

    /**
     * @param \DateTime $registrationsStart
     * @return Nosecounter
     */
    public function setRegistrationsStart($registrationsStart) {
        $this->registrationsStart = $registrationsStart;
        return $this;
    }

    /**
     * @return \DateTime
     */
    public function getRegistrationsEnd() {
        return $this->registrationsEnd;
    }

    /**
     * @param \DateTime $registrationsEnd
     * @return Nosecounter
     */
    public function setRegistrationsEnd($registrationsEnd) {
        $this->registrationsEnd = $registrationsEnd;
        return $this;
    }

    /**
     * @return int
     */
    public function getRegistrationsInterval() {
        return $this->registrationsInterval;
    }

    /**
     * @param int $registrationsInterval
     * @return Nosecounter
     */
    public function setRegistrationsInterval($registrationsInterval) {
        $this->registrationsInterval = $registrationsInterval;
        return $this;
    }

    /**
     * @return int
     */
    public function getTopCountryCount() {
        return $this->topCountryCount;
    }

    /**
     * @param int $topCountryCount
     * @return Nosecounter
     */
    public function setTopCountryCount($topCountryCount) {
        $this->topCountryCount = $topCountryCount;
        return $this;
    }

    /**
     * @return int
     */
    public function getMinAge() {
        return $this->minAge;
    }

    /**
     * @param int $minAge
     * @return Nosecounter
     */
    public function setMinAge($minAge) {
        $this->minAge = $minAge;
        return $this;
    }

    /**
     * @return array
     */
    public function getGenderList() {
        return $this->genderList;
    }

    /**
     * @param array $genderList
     * @return Nosecounter
     */
    public function setGenderList($genderList) {
        $this->genderList = $genderList;
        return $this;
    }

    /**
     * @return array
     */
    public function getSponsorList() {
        return $this->sponsorList;
    }

    /**
     * @param array $sponsorList
     * @return Nosecounter
     */
    public function setSponsorList($sponsorList) {
        $this->sponsorList = $sponsorList;
        return $this;
    }

    /**
     * @return array
     */
    public function getSpecialInterestList() {
        return $this->specialInterestList;
    }

    /**
     * @param array $specialInterestList
     * @return Nosecounter
     */
    public function setSpecialInterestList($specialInterestList) {
        $this->specialInterestList = $specialInterestList;
        return $this;
    }

    /**
     * @return array
     */
    public function getShirtSizeList() {
        return $this->shirtSizeList;
    }

    /**
     * @param array $shirtSizeList
     * @return Nosecounter
     */
    public function setShirtSizeList($shirtSizeList) {
        $this->shirtSizeList = $shirtSizeList;
        return $this;
    }
}
