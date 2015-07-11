<?php

namespace GitlabChangelog;

class GitlabChangelog 
{
    public $url; // gitlab url
    public $repo; // repo path with namespace (eg. ata/atatech-kb)
    public $token; // private token
    public $debug = false;
    public $milestoneFilter;
    public $getLabels;

    public function __construct()
    {
        $this->milestoneFilter = function($milestone) {
            return true;
        };

        $this->getLabels = function($issue) {
            return $issue->labels;
        };
    }

    private function get($arg)
    {
        $url = $this->url . 'api/v3/' . $arg;
        if ($this->debug) {
            echo $url . PHP_EOL;
        }
        if (strripos($url, '?') !== false) {
            $url .= '&';
        } else {
            $url .= '?';
        }
        $url .= 'private_token=' . $this->token;
        return json_decode(file_get_contents($url));
    }

    private function getRepo($page = 1, $perPage = 100)
    {
        $projects = $this->get('projects?page=' . $page . '&per_page=' . $perPage);

        $filteredProjects = array_filter($projects, function($repo) {
             echo "Checking " . $repo->path_with_namespace . '...' . PHP_EOL;
             return $repo->path_with_namespace === $this->repo;
        });

        // Not found. Try next page
        if (count($filteredProjects) == 0) {
            $isLastPage = count($projects) < $perPage;
            if (!$isLastPage) {
                return $this->getRepo(++$page, $perPage);
            }
        }
        else {
            return array_pop($filteredProjects);
        }
    }

    // issues (recent issues has lower index)
    private function getIssues($repo, $page = 1, $perPage = 100)
    {
        if (!isset($repo) || !isset($repo->id)) {
            echo "Repo not found" . PHP_EOL;
            exit(1);
        }

        $issues = [];
        while (true) {
            $next = $this->get('projects/' . $repo->id . '/issues?page=' . $page . '&per_page=' . $perPage);
            $count = count($next);
            $issues = array_merge($issues, $next);
            $page++;
            if ($count < $perPage) {
                break;
            }
        }
        return array_reverse(array_filter($issues, function($issue) {
            return $issue->state === "closed" && isset($issue->milestone);
        }));
    }

    private function getMilestones($repo)
    {
        $milestones = $this->get('projects/'. $repo->id . '/milestones');

        usort($milestones, function($a, $b) {
            $date = strcmp($b->due_date, $a->due_date);

            if ($date != 0) {
                return $date;
            }

            return strcmp($b->title, $a->title);

        });
        return $milestones;
    }

    public function markdown()
    {
        $repo = $this->getRepo();
        $issues = $this->getIssues($repo);

        if (!$issues || empty($issues)) {
            return null;
        }

        $milestones = $this->getMilestones($repo);

        $markdown = array_map(function($milestone) use ($issues, $milestones, $repo) {

            $milestoneIssues = array_filter($issues, function($issue) use ($milestone) {
                return $issue->milestone->id == $milestone->id;
            });

            if (count($milestoneIssues) === 0) {
                return "";
            }

            // don't use this->milestoneFilter(milestone)
            // it's lambda!
            if (!call_user_func($this->milestoneFilter, $milestone)) {
                return "";
            }

            $text = array_map(function($issue) use ($repo) {

                $labels = call_user_func($this->getLabels, $issue);
                $labels = implode(', ', $labels);
                $tag = call_user_func($this->getTag, $issue);
                $str = "- `".$labels."` [#".$issue->iid."] ";
                $str .= "(" . $this->url . $repo->path_with_namespace . "/issues/" . $issue->iid . ") ";
                $str .= $tag.$issue->title;
                return $str;
            }, $milestoneIssues);

            $date = date_parse($milestone->due_date);
            $res = "## " . $milestone->title;

            if ($milestone->state === "active") {
                $res .= " (Unreleased)";
            }

            $res .= " - _" . $date["year"] . "-" . $date["month"] . "-" . $date["day"] . "_" . PHP_EOL . join($text, PHP_EOL) . PHP_EOL . PHP_EOL;
            return $res;
        }, $milestones);
        $markdown = "# Changelog" . PHP_EOL . PHP_EOL . join($markdown, "");
        return $markdown;
    }
}
