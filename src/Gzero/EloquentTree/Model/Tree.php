<?php namespace Gzero\EloquentTree\Model;


use Illuminate\Database\Eloquent\Collection;

/**
 * Class Tree
 *
 * @package Gzero\EloquentTree\Model
 */
class Tree extends \Illuminate\Database\Eloquent\Model {

    /**
     * Parent object
     *
     * @var static
     */
    protected $_parent;
    /**
     * Array for children elements
     *
     * @var array
     */
    public $children;
    /**
     * Database mapping tree fields
     *
     * @var Array
     */
    protected static $_tree_cols = array(
        'path'   => 'path',
        'parent' => 'parent_id',
        'level'  => 'level'
    );

    /**
     * Set node as root node
     *
     * @return $this
     */
    public function setAsRoot()
    {
        $this->_handleNewNodes();
        $this->{$this->getTreeColumn('path')}   = $this->{$this->getKeyName()} . '/';
        $this->{$this->getTreeColumn('parent')} = NULL;
        $this->{$this->getTreeColumn('level')}  = 0;
        $this->save();
        $this->_updateChildren($this);
        return $this;
    }

    /**
     * Set node as child of $parent node
     *
     * @param Tree $parent
     *
     * @return $this
     */
    public function setChildOf(Tree $parent)
    {
        $this->_handleNewNodes();
        $this->{$this->getTreeColumn('path')}   = $parent->{$this->getTreeColumn('path')} . $this->{$this->getKeyName()} . '/';
        $this->{$this->getTreeColumn('parent')} = $parent->{$this->getKeyName()};
        $this->{$this->getTreeColumn('level')}  = $parent->{$this->getTreeColumn('level')} + 1;
        $this->save();
        $this->_updateChildren($this);
        return $this;
    }

    /**
     * Set node as sibling of $sibling node
     *
     * @param Tree $sibling
     *
     * @return $this
     */
    public function setSiblingOf(Tree $sibling)
    {
        $this->_handleNewNodes();
        $this->{$this->getTreeColumn('path')}   =
            preg_replace('/\d\/$/', '', $sibling->{$this->getTreeColumn('path')}) . $this->{$this->getKeyName()} . '/';
        $this->{$this->getTreeColumn('parent')} = $sibling->{$this->getTreeColumn('parent')};
        $this->{$this->getTreeColumn('level')}  = $sibling->{$this->getTreeColumn('level')};
        $this->save();
        $this->_updateChildren($this);
        return $this;
    }

    /**
     * Check if node is root
     *
     * @return bool
     */
    public function isRoot()
    {
        return (empty($this->{$this->getTreeColumn('parent')})) ? TRUE : FALSE;
    }

    /**
     * Check if node is leaf
     *
     * @return bool
     */
    public function isLeaf()
    {
        return (bool) static::where($this->getTreeColumn('parent'), '=', $this->{$this->getKeyName()})->count();
    }

    /**
     * Get parent to specific node (if exist)
     *
     * @return static
     */
    public function getParent()
    {
        if ($this->{$this->getTreeColumn('parent')}) {
            if (!$this->_parent) {
                return $this->_parent = static::where($this->getKeyName(), '=', $this->{$this->getTreeColumn('parent')})
                    ->first();
            }
            return $this->_parent;
        }
        return NULL;
    }


    /**
     * Find all children for specific node
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function findChildren()
    {
        return static::where($this->getTreeColumn('parent'), '=', $this->{$this->getKeyName()});
    }

    /**
     * Find all descendants for specific node
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function findDescendants()
    {
        return static::where($this->getTreeColumn('path'), 'LIKE', $this->{$this->getTreeColumn('path')} . '%')
            ->where($this->getKeyName(), '!=', $this->{$this->getKeyName()})
            ->orderBy($this->getTreeColumn('level'), 'ASC');
    }

    /**
     * Find all ancestors for specific node
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function findAncestors()
    {
        return static::whereIn($this->getKeyName(), $this->_extractPath())
            ->where($this->getKeyName(), '!=', $this->{$this->getKeyName()})
            ->orderBy($this->getTreeColumn('level'), 'ASC');
    }

    /**
     * Find root for this node
     *
     * @return $this
     */
    public function findRoot()
    {
        if ($this->isRoot()) {
            return $this;
        } else {
            $extractedPath = $this->_extractPath();
            $root_id       = array_shift($extractedPath);
            return static::where($this->getKeyName(), '=', $root_id)->first();
        }
    }

    /**
     * Rebuilds sub-tree for this node
     *
     * @param \Illuminate\Database\Eloquent\Collection $nodes     Nodes from which we are build tree
     * @param string                                   $presenter Optional presenter class
     *
     * @return $this
     */
    public function buildTree(Collection $nodes, $presenter = '')
    {
        $nodes->prepend($this); // Set current node as root
        static::buildCompleteTree($nodes, $presenter);
        return $this;
    }

    //---------------------------------------------------------------------------------------------------------------
    // START                                 STATIC
    //---------------------------------------------------------------------------------------------------------------

    protected static function boot()
    {
        parent::boot();
        static::observe(new Observer());
    }

    /**
     * ONLY FOR TESTS!
     * Metod resets static::$booted
     */
    public static function __resetBootedStaticProperty()
    {
        static::$booted = array();
    }

    /**
     * Get tree column for actual model
     *
     * @param string $name column name [path|parent|level]
     *
     * @return null
     */
    public static function getTreeColumn($name)
    {
        if (!empty(static::$_tree_cols[$name])) {
            return static::$_tree_cols[$name];
        }
        return NULL;
    }

    /**
     * Gets all root nodes
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function getRoots()
    {
        return static::where(static::getTreeColumn('parent'), 'IS', DB::raw('NULL'));
    }

    /**
     * Get all nodes in tree (with root node)
     *
     * @param int $root_id Root node id
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function fetchTree($root_id)
    {
        return static::where(static::getTreeColumn('path'), 'LIKE', "$root_id/%")
            ->orderBy(static::getTreeColumn('level'), 'ASC');
    }

    /**
     * Rebuilds the entire tree on the PHP side
     *
     * @param \Illuminate\Database\Eloquent\Collection $nodes     Nodes from which we are build tree
     * @param string                                   $presenter Optional presenter class
     *
     * @return static Root node
     * @throws \Exception
     */
    public static function buildCompleteTree(Collection $nodes, $presenter = '')
    {
        $count = 0;
        $refs  = array(); // Reference table to store records in the construction of the tree
        foreach ($nodes as &$node) {
            /* @var Tree $node */
            $refs[$node->{$node->getKeyName()}] = & $node; // Adding to ref table (we identify after the id)
            if ($count === 0) { // We use this condition as a factor in building subtrees, root node is always 1
                $root = & $node;
                $count++;
            } else { // This is not a root, so add them to the parent
                if (!empty($presenter)) {
                    if (class_exists($presenter)) {
                        $refs[$node->{static::getTreeColumn('parent')}]->_addChildToCollection(new $presenter($node));
                    } else {
                        throw new \Exception("No presenter class found: $presenter");
                    }
                } else {
                    $refs[$node->{static::getTreeColumn('parent')}]->_addChildToCollection($node);
                }
            }
        }
        return (!isset($root)) ? FALSE : $root;
    }

    //---------------------------------------------------------------------------------------------------------------
    // END                                  STATIC
    //---------------------------------------------------------------------------------------------------------------

    //---------------------------------------------------------------------------------------------------------------
    // START                         PROTECTED/PRIVATE
    //---------------------------------------------------------------------------------------------------------------

    /**
     * Creating node if not exist
     */
    protected function _handleNewNodes()
    {
        if (!$this->exists) {
            $this->save();
        }
    }

    /**
     * Extract path to array
     *
     * @return array
     */
    protected function _extractPath()
    {
        $path = explode('/', $this->{$this->getTreeColumn('path')});
        array_pop($path); // Remove last empty element
        return $path;
    }

    /**
     * Adds children for this node while building the tree structure in PHP
     *
     * @param Tree $child Child node
     */
    protected function _addChildToCollection(Tree &$child)
    {
        if (empty($this->children)) {
            $this->children = new Collection();
        }
        $this->children->add($child);
    }

    /**
     * Recursive node updating
     *
     * @param Tree $parent
     */
    protected function _updateChildren(Tree $parent)
    {
        foreach ($parent->findChildren()->get() as $child) {
            $child->{$this->getTreeColumn('level')} = $parent->{$this->getTreeColumn('level')} + 1;
            $child->{$this->getTreeColumn('path')}  = $parent->{$this->getTreeColumn('path')} .
                $child->{$this->getKeyName()} . '/';
            $child->save();
            $this->_updateChildren($child);
        }
    }

    //---------------------------------------------------------------------------------------------------------------
    // END                          PROTECTED/PRIVATE
    //---------------------------------------------------------------------------------------------------------------

}
