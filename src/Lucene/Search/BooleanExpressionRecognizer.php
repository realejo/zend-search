<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/zf2 for the canonical source repository
 * @copyright Copyright (c) 2005-2012 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 * @package   Zend_Search
 */

namespace ZendSearch\Lucene\Search;

use ZendSearch\Lucene;

/**
 * @category   Zend
 * @package    Zend_Search_Lucene
 * @subpackage Search
 */
class BooleanExpressionRecognizer extends Lucene\AbstractFSM
{
    /** State Machine states */
    const ST_START           = 0;
    const ST_LITERAL         = 1;
    const ST_NOT_OPERATOR    = 2;
    const ST_AND_OPERATOR    = 3;
    const ST_OR_OPERATOR     = 4;

    /** Input symbols */
    const IN_LITERAL         = 0;
    const IN_NOT_OPERATOR    = 1;
    const IN_AND_OPERATOR    = 2;
    const IN_OR_OPERATOR     = 3;


    /**
     * NOT operator signal
     *
     * @var boolean
     */
    private $_negativeLiteral = false;

    /**
     * Current literal
     *
     * @var mixed
     */
    private $_literal;


    /**
     * Set of boolean query conjunctions
     *
     * Each conjunction is an array of conjunction elements
     * Each conjunction element is presented with two-elements array:
     * array(<literal>, <is_negative>)
     *
     * So, it has a structure:
     * array( array( array(<literal>, <is_negative>), // first literal of first conjuction
     *               array(<literal>, <is_negative>), // second literal of first conjuction
     *               ...
     *               array(<literal>, <is_negative>)
     *             ), // end of first conjuction
     *        array( array(<literal>, <is_negative>), // first literal of second conjuction
     *               array(<literal>, <is_negative>), // second literal of second conjuction
     *               ...
     *               array(<literal>, <is_negative>)
     *             ), // end of second conjuction
     *        ...
     *      ) // end of structure
     *
     * @var array
     */
    private $_conjunctions = [];

    /**
     * Current conjuction
     *
     * @var array
     */
    private $_currentConjunction = [];


    /**
     * Object constructor
     */
    public function __construct()
    {
        parent::__construct(
            [self::ST_START,
                                   self::ST_LITERAL,
                                   self::ST_NOT_OPERATOR,
                                   self::ST_AND_OPERATOR,
                                   self::ST_OR_OPERATOR],
            [self::IN_LITERAL,
                                   self::IN_NOT_OPERATOR,
                                   self::IN_AND_OPERATOR,
             self::IN_OR_OPERATOR]
        );

        $emptyOperatorAction    = new Lucene\FSMAction($this, 'emptyOperatorAction');
        $emptyNotOperatorAction = new Lucene\FSMAction($this, 'emptyNotOperatorAction');

        $this->addRules([ [self::ST_START,        self::IN_LITERAL,        self::ST_LITERAL],
                               [self::ST_START,        self::IN_NOT_OPERATOR,   self::ST_NOT_OPERATOR],

                               [self::ST_LITERAL,      self::IN_AND_OPERATOR,   self::ST_AND_OPERATOR],
                               [self::ST_LITERAL,      self::IN_OR_OPERATOR,    self::ST_OR_OPERATOR],
                               [self::ST_LITERAL,      self::IN_LITERAL,        self::ST_LITERAL,      $emptyOperatorAction],
                               [self::ST_LITERAL,      self::IN_NOT_OPERATOR,   self::ST_NOT_OPERATOR, $emptyNotOperatorAction],

                               [self::ST_NOT_OPERATOR, self::IN_LITERAL,        self::ST_LITERAL],

                               [self::ST_AND_OPERATOR, self::IN_LITERAL,        self::ST_LITERAL],
                               [self::ST_AND_OPERATOR, self::IN_NOT_OPERATOR,   self::ST_NOT_OPERATOR],

                               [self::ST_OR_OPERATOR,  self::IN_LITERAL,        self::ST_LITERAL],
                               [self::ST_OR_OPERATOR,  self::IN_NOT_OPERATOR,   self::ST_NOT_OPERATOR],
                             ]);

        $notOperatorAction     = new Lucene\FSMAction($this, 'notOperatorAction');
        $orOperatorAction      = new Lucene\FSMAction($this, 'orOperatorAction');
        $literalAction         = new Lucene\FSMAction($this, 'literalAction');


        $this->addEntryAction(self::ST_NOT_OPERATOR, $notOperatorAction);
        $this->addEntryAction(self::ST_OR_OPERATOR, $orOperatorAction);
        $this->addEntryAction(self::ST_LITERAL, $literalAction);
    }


    /**
     * Process next operator.
     *
     * Operators are defined by class constants: IN_AND_OPERATOR, IN_OR_OPERATOR and IN_NOT_OPERATOR
     *
     * @param integer $operator
     */
    public function processOperator($operator)
    {
        $this->process($operator);
    }

    /**
     * Process expression literal.
     *
     * @param integer $operator
     */
    public function processLiteral($literal)
    {
        $this->_literal = $literal;

        $this->process(self::IN_LITERAL);
    }

    /**
     * Finish an expression and return result
     *
     * Result is a set of boolean query conjunctions
     *
     * Each conjunction is an array of conjunction elements
     * Each conjunction element is presented with two-elements array:
     * array(<literal>, <is_negative>)
     *
     * So, it has a structure:
     * array( array( array(<literal>, <is_negative>), // first literal of first conjuction
     *               array(<literal>, <is_negative>), // second literal of first conjuction
     *               ...
     *               array(<literal>, <is_negative>)
     *             ), // end of first conjuction
     *        array( array(<literal>, <is_negative>), // first literal of second conjuction
     *               array(<literal>, <is_negative>), // second literal of second conjuction
     *               ...
     *               array(<literal>, <is_negative>)
     *             ), // end of second conjuction
     *        ...
     *      ) // end of structure
     *
     * @throws \ZendSearch\Lucene\Exception\UnexpectedValueException
     * @return array
     */
    public function finishExpression()
    {
        if ($this->getState() != self::ST_LITERAL) {
            throw new Lucene\Exception\UnexpectedValueException('Literal expected.');
        }

        $this->_conjunctions[] = $this->_currentConjunction;

        return $this->_conjunctions;
    }



    /*********************************************************************
     * Actions implementation
     *********************************************************************/

    /**
     * default (omitted) operator processing
     */
    public function emptyOperatorAction()
    {
        if (QueryParser::getDefaultOperator() == QueryParser::B_AND) {
            // Do nothing
        } else {
            $this->orOperatorAction();
        }

        // Process literal
        $this->literalAction();
    }

    /**
     * default (omitted) + NOT operator processing
     */
    public function emptyNotOperatorAction()
    {
        if (QueryParser::getDefaultOperator() == QueryParser::B_AND) {
            // Do nothing
        } else {
            $this->orOperatorAction();
        }

        // Process NOT operator
        $this->notOperatorAction();
    }


    /**
     * NOT operator processing
     */
    public function notOperatorAction()
    {
        $this->_negativeLiteral = true;
    }

    /**
     * OR operator processing
     * Close current conjunction
     */
    public function orOperatorAction()
    {
        $this->_conjunctions[]     = $this->_currentConjunction;
        $this->_currentConjunction = [];
    }

    /**
     * Literal processing
     */
    public function literalAction()
    {
        // Add literal to the current conjunction
        $this->_currentConjunction[] = [$this->_literal, ! $this->_negativeLiteral];

        // Switch off negative signal
        $this->_negativeLiteral = false;
    }
}
