<?php

namespace ActivityStreams;

use Bundle\CodeReviewBundle\CodeStructure\Rating\Classifier;
use Bundle\CodeReviewBundle\Entity\BuildStatus;
use Bundle\CodeReviewBundle\Entity\CodeStructure\Element;
use Bundle\CodeReviewBundle\Entity\Metadata\GithubPullRequestMetadata;
use Bundle\CodeReviewBundle\Entity\Review;
use Bundle\CodeReviewBundle\Entity\SemanticDiff\IndexDiff;
use Bundle\CodeReviewBundle\Entity\SystemReviewComment;
use Router\ObjectRouter;
use JMS\DiExtraBundle\Annotation as DI;













/**
 * @DI\Service("activity.inspection_data_collector")
 */
class InspectionDataCollector
{
    private $objectRouter;
    private $ratingClassifier;

    /**
     * @DI\InjectParams
     */
    public function __construct(ObjectRouter $objectRouter, Classifier $ratingClassifier)
    {
        $this->objectRouter = $objectRouter;
        $this->ratingClassifier = $ratingClassifier;
    }

    public function collectDataForCompletedInspection(Review $review)
    {
        $data = array(
            'path' => $this->objectRouter->generate('view', $review),
            'title' => $review->getMetadata()->getTitle(),
            'is_pull_request' => $review->getMetadata() instanceof GithubPullRequestMetadata,
            'failures' => array(),
        );

        if ($review->getAnalysisBuildStatus()->isFailed()) {
            $data['failures'] = $review->getAnalysisBuildStatus()->getDetails()['failures'];
        }

        if ($review->hasIndex()) {
            $system = $review->getIndex()->getRootCodeElement();

            if ($system->hasMetricValue('scrutinizer.nb_issues')) {
                $data['nb_issues'] = $system->getMetricValue('scrutinizer.nb_issues');
            }

            if ($system->hasMetricValue('scrutinizer.quality')) {
                $data['quality_score'] = $system->getMetricValue('scrutinizer.quality');
            }
        }

        if (null !== $diff = $review->getIndexDiff()) {
            $diffData = $this->collectDiffData($diff);
            if ($this->hasDiffDataChanges($diffData)) {
                $data['diff'] = $diffData;
            }
        }

        return $data;
    }

    public function collectDiffData(IndexDiff $diff)
    {
        $diffData = array(
            'base' => $diff->getBaseIndex()->getSourceReference(),
            'head' => $diff->getHeadIndex()->getSourceReference(),
            'patches' => array(
                'new' => 0,
                'categories' => array(),
            ),
            'issues' => array(
                'new' => array(
                    'total' => $diff->getNbAddedComments(),
                    'by_severity' => array(),
                ),
                'nb_fixed' => $diff->getNbRemovedComments(),
            ),
            'elements' => array(
                'new' => array(
                    'total' => 0,
                    'worst' => array(),
                ),
                'changed' => array(
                    'total' => 0,
                    'biggest' => array(),
                ),
                'removed' => array(
                    'total' => 0,
                    'worst' => array(),
                ),
            ),
        );

        $this->addNewIssuesBySeverity($diff, $diffData);
        $this->addCodeElementInfo($diff, $diffData);
        $this->addCodeCoverageInfo($diff, $diffData);
        $this->addPatchesInfo($diff, $diffData);

        return $diffData;
    }

    private function hasDiffDataChanges(array $diffData)
    {
        // We do not consider removed elements here, but only display them
        // if there are also other changes.

        return $diffData['issues']['new']['total'] > 0 || $diffData['issues']['nb_fixed'] > 0
                    || $diffData['elements']['new']['total'] > 0
                    || $diffData['elements']['changed']['total'] > 0
                    || $diffData['patches']['new'] > 0
                    || isset($diffData['test_coverage']);
    }

    private function addCodeCoverageInfo(IndexDiff $diff, array &$data)
    {
        if ( ! $diff->getHeadIndex()->getRootCodeElement()->hasMetricValue('scrutinizer.test_coverage')
                || ! $diff->getBaseIndex()->getRootCodeElement()->hasMetricValue('scrutinizer.test_coverage')) {
            return;
        }

        $headValue = $diff->getHeadIndex()->getRootCodeElement()->getMetricValue('scrutinizer.test_coverage');
        $baseValue = $diff->getBaseIndex()->getRootCodeElement()->getMetricValue('scrutinizer.test_coverage');

        if ((integer) round(abs($headValue - $baseValue) * 100) < 1) {
            return;
        }

        $data['test_coverage'] = array(
            'total' => $headValue,
            'change' => $headValue - $baseValue,
        );
    }

    private function addCodeElementInfo(IndexDiff $diff, array &$data)
    {
        $this->addAddedCodeElementsInfo($diff, $data);
        $this->addChangedCodeElementsInfo($diff, $data);
        $this->addRemovedCodeElementsInfo($diff, $data);
    }

    private function addRemovedCodeElementsInfo(IndexDiff $diff, array &$data)
    {
        $ratedElements = array_filter($diff->getRemovedCodeElements(), function(Element $element) {
            return $element->hasMetric('scrutinizer.quality');
        });

        usort($ratedElements, function(Element $a, Element $b) {
            return $a->getMetricValue('scrutinizer.quality') - $b->getMetricValue('scrutinizer.quality');
        });

        $data['elements']['removed']['total'] = count($ratedElements);
        foreach (array_slice($ratedElements, 0, 10) as $removedElement) {
            /** @var Element $removedElement */

            $data['elements']['removed']['worst'][] = array(
                'type' => $removedElement->getType(),
                'identifier' => $removedElement->getIdentifier(),
                'rating' => $removedElement->getMetricValue('scrutinizer.quality'),
            );
        }
    }

    private function addChangedCodeElementsInfo(IndexDiff $diff, array &$data)
    {
        $metricDiffs = array();

        $elementDiffs = $diff->getChangedCodeElements();
        foreach ($elementDiffs as $elementDiff) {
            $changedMetrics = $elementDiff->getChangedMetrics();

            if ( ! isset($changedMetrics['scrutinizer.quality'])) {
                continue;
            }

            $qualityDiff = $changedMetrics['scrutinizer.quality'];

            $baseClass = $this->ratingClassifier->classify($qualityDiff->getBaseValue());
            $headClass = $this->ratingClassifier->classify($qualityDiff->getHeadValue());

            if ($baseClass !== $headClass) {
                $metricDiffs[] = array(
                    'base' => $qualityDiff->getBaseValue(),
                    'head' => $qualityDiff->getHeadValue(),
                    'element' => $elementDiff->getHeadElement(),
                );
            }
        }

        usort($metricDiffs, function(array $a, array $b) {
            return abs($b['base'] - $b['head']) - abs($a['base'] - $a['head']);
        });

        $data['elements']['changed']['total'] = count($metricDiffs);
        foreach (array_slice($metricDiffs, 0, 10) as $metricDiff) {
            $data['elements']['changed']['biggest'][] = array(
                'type' => $metricDiff['element']->getType(),
                'identifier' => $metricDiff['element']->getIdentifier(),
                'rating' => array(
                    'base' => $metricDiff['base'],
                    'head' => $metricDiff['head'],
                ),
            );
        }
    }

    private function addAddedCodeElementsInfo(IndexDiff $diff, array &$data)
    {
        $elements = $diff->getAddedCodeElements();

        $ratedElements = array_filter($elements, function(Element $element) {
            return $element->hasMetric('scrutinizer.quality');
        });

        usort($ratedElements, function(Element $a, Element $b) {
            return $a->getMetricValue('scrutinizer.quality') - $b->getMetricValue('scrutinizer.quality');
        });

        $data['elements']['new']['total'] = count($ratedElements);
        foreach (array_slice($ratedElements, 0, 10) as $element) {
            /** @var Element $element */

            $data['elements']['new']['worst'][] = array(
                'type' => $element->getType(),
                'identifier' => $element->getIdentifier(),
                'rating' => $element->getMetricValue('scrutinizer.quality'),
            );
        }
    }

    private function addPatchesInfo(IndexDiff $diff, array &$data)
    {
        $data['patches']['new'] = $diff->getNbNewFilePatches();

        $categories = array();
        foreach ($diff->getNewFilePatches() as $patch) {
            $categories[] = $patch->getName();
        }

        $data['patches']['categories'] = array_values(array_unique($categories, SORT_STRING));
    }

    private function addNewIssuesBySeverity(IndexDiff $diff, array &$data)
    {
        // New issues by severity
        foreach ($diff->getAddedComments() as $comment) {
            /** @var SystemReviewComment $comment */

            if ($comment->isAutoHidden()) {
                continue;
            }

            $type = $comment->getSeverity()->getType();
            if ( ! isset($data['issues']['new']['by_severity'][$type])) {
                $data['issues']['new']['by_severity'][$type] = 0;
            }

            $data['issues']['new']['by_severity'][$type] += 1;
        }
    }
}
