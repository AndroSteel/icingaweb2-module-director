<?php

namespace ipl\Web\Table;

use Countable;
use Icinga\Application\Benchmark;
use Icinga\Data\Filter\Filter;
use ipl\Data\Paginatable;
use ipl\Db\Zf1\FilterRenderer;
use ipl\Html\Table;
use ipl\Translation\TranslationHelper;
use ipl\Web\Widget\ControlsAndContent;
use ipl\Web\Widget\Paginator;
use ipl\Web\Table\Extension\QuickSearch;
use ipl\Web\Url;

abstract class QueryBasedTable extends Table implements Countable
{
    use TranslationHelper;
    use QuickSearch;

    protected $defaultAttributes = [
        'class' => ['common-table', 'table-row-selectable'],
        'data-base-target' => '_next',
    ];

    private $fetchedRows;

    protected $lastDay;

    private $isUsEnglish;

    protected $searchColumns = [];

    /**
     * @return Paginatable
     */
    abstract protected function getPaginationAdapter();

    abstract public function getQuery();

    public function getPaginator(Url $url)
    {
        return new Paginator(
            $this->getPaginationAdapter(),
            $url
        );
    }

    public function count()
    {
        return $this->getPaginationAdapter()->count();
    }

    public function applyFilter(Filter $filter)
    {
        FilterRenderer::applyToQuery($filter, $this->getQuery());
        return $this;
    }

    protected function getSearchColumns()
    {
        return $this->searchColumns;
    }

    protected function search($search)
    {
        if (! empty($search)) {
            $query = $this->getQuery();
            $columns = $this->getSearchColumns();
            if (strpos($search, ' ') === false) {
                $filter = Filter::matchAny();
                foreach ($columns as $column) {
                    $filter->addFilter(Filter::expression($column, '=', "*$search*"));
                }
            } else {
                $filter = Filter::matchAll();
                foreach (explode(' ', $search) as $s) {
                    $sub = Filter::matchAny();
                    foreach ($columns as $column) {
                        $sub->addFilter(Filter::expression($column, '=', "*$s*"));
                    }
                    $filter->addFilter($sub);
                }
            }

            FilterRenderer::applyToQuery($filter, $query);
        }

        return $this;
    }

    abstract protected function prepareQuery();

    public function renderContent()
    {
        if (count($this->getColumnsToBeRendered())) {
            $this->generateHeader();
        }
        $this->fetchRows();

        return parent::renderContent();
    }

    protected function splitByDay($timestamp)
    {
        $this->renderDayIfNew((int) $timestamp);
    }

    protected function fetchRows()
    {
        foreach ($this->fetch() as $row) {
            // Hint: do not fetch the body first, the row might want to replace it
            $tr = $this->renderRow($row);
            $this->body()->add($tr);
        }
    }


    protected function isUsEnglish()
    {
        if ($this->isUsEnglish === null) {
            $this->isUsEnglish = in_array(setlocale(LC_ALL, 0), array('en_US.UTF-8', 'C'));
        }

        return $this->isUsEnglish;
    }

    /**
     * @param  int $timestamp
     */
    protected function renderDayIfNew($timestamp)
    {
        if ($this->isUsEnglish()) {
            $day = date('l, jS F Y', $timestamp);
        } else {
            $day = strftime('%A, %e. %B, %Y', $timestamp);
        }

        if ($this->lastDay !== $day) {
            $this->nextHeader()->add(
                $this::th($day, [
                    'colspan' => 2,
                    'class'   => 'table-header-day'
                ])
            );

            $this->lastDay = $day;
            $this->nextBody();
        }
    }

    abstract protected function fetchQueryRows();

    public function fetch()
    {
        $parts = explode('\\', get_class($this));
        $name = end($parts);
        Benchmark::measure("Fetching data for $name table");
        $rows = $this->fetchQueryRows();
        $this->fetchedRows = count($rows);
        Benchmark::measure("Fetched $this->fetchedRows rows for $name table");

        return $rows;
    }

    protected function initializeOptionalQuickSearch(ControlsAndContent $controller)
    {
        $columns = $this->getSearchColumns();
        if (! empty($columns)) {
            $this->search(
                $this->getQuickSearch(
                    $controller->controls(),
                    $controller->url()
                )
            );
        }
    }

    /**
     * @param ControlsAndContent $controller
     * @return $this
     */
    public function renderTo(ControlsAndContent $controller)
    {
        $url = $controller->url();
        $c = $controller->content();
        $paginator = $this->getPaginator($url);
        $this->initializeOptionalQuickSearch($controller);
        $controller->actions()->add($paginator);
        $c->add($this);

        // TODO: move elsewhere
        if (method_exists($this, 'dumpSqlQuery')) {
            if ($url->getParam('format') === 'sql') {
                $c->prepend($this->dumpSqlQuery($url));
            }
        }

        return $this;
    }
}