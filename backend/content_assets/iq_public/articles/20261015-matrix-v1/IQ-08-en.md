A turn and a mirror can put one mark in the same place. To tell them apart, follow the other marks as well. These five original worked exercises show how to check a rotation, locate a reflection axis, separate movement from turning, and recognise when a symmetric picture cannot identify a unique operation.

The diagrams and solutions were created for this article. They are separate from FermatMind's scored questions and from other providers' test banks. If you want the broader method for counting and combining shapes, start with the [three introductory matrix examples](/en/articles/iq-test-tool-guide).

## Read the diagrams before trying a rule

Each small board is a square array of positions. A dot means an empty position. The digits 1, 2 and 3 identify three different markers: move their positions, but keep the printed labels upright. Do not rotate the typography or treat the labels as quantities. The outer grid labels r1/c1 mean row 1/column 1. A question-mark board is missing. Options A–D appear in a separate diagram below it.

For a text alternative, a code such as `1../..2/.3.` gives the board's top, middle and bottom rows, separated by slashes. Here marker 1 is at top left, 2 at middle right, and 3 at bottom centre. You can reconstruct every board from the row codes supplied with each exercise. All decisions use positions and marker identities, not colour.

A **rotation** turns every marker through the same angle around a specified centre. A **reflection** maps each marker across a specified line: the perpendicular distance from that line stays the same and the side changes, except for a marker on the line. In the exercises below, turns are about the centre of each small board; mirror axes run vertically or horizontally through that centre. The outer matrix itself is not being rotated or reflected.

Try following two markers first, then use the third as a check. One matching landmark does not establish a matching transformation. With three distinct non-collinear markers, their clockwise order is preserved by a rotation and reversed by a reflection. This check needs identifiable markers; an unmarked symmetric shape can conceal the difference.

## Exercise 1: a matching corner is not enough

In each row, apply the same one-step operation twice, from left to right. Compare these candidates: a clockwise quarter-turn, a half-turn, a vertical-axis reflection, or no change. The starting board may differ between rows. Choose the missing board under the operation that fits **all** completed row steps. No additional down-column rule is stipulated.

```text
   c1    c2    c3
r1 1.. | ..1 | .3.
   ..2 | 3.. | 2..
   .3. | .2. | ..1
   ----+-----+----
   c1    c2    c3
r2 .3. | .2. | 1..
   2.. | ..3 | ..2
   ..1 | 1.. | .3.
   ----+-----+----
   c1    c2    c3
r3 .2. | 1.. | ???
   ..3 | ..2 | ???
   1.. | .3. | ???
```

```text
   A      B
   1..    .3.
   ..3    2..
   .2.    ..1
   C      D
   ..1    1..
   3..    ..2
   .2.    .3.
```

Text alternative, by outer row: r1 = `1../..2/.3.`, `..1/3../.2.`, `.3./2../..1`; r2 = `.3./2../..1`, `.2./..3/1..`, `1../..2/.3.`; r3 = `.2./..3/1..`, `1../..2/.3.`, missing. Options: A `1../..3/.2.`; B `.3./2../..1`; C `..1/3../.2.`; D `1../..2/.3.`.

**Answer: C.** In r1c1, a clockwise quarter-turn takes marker 1 from top left to top right, marker 2 from middle right to bottom centre, and marker 3 from bottom centre to middle left. That gives r1c2 exactly. Repeat the turn to obtain r1c3. In row 2, a half-turned starting board advances to the three-quarter-turn board, then to the starting orientation; both known steps check the same operation.

Row 3 ends its known part at the original board, so the next board is the clockwise quarter-turn, C. A also puts marker 2 at bottom centre, but puts marker 1 at top left and 3 at middle right. C requires them at top right and middle left: matching marker 2 was insufficient. B turns the last known board by 180°, not 90°. D makes no change. The vertical-mirror candidate already fails the first step: it would keep marker 2 in the middle row, not send it to the bottom row.

This establishes the answer among the four stated candidate operations and the displayed options. A finite teaching grid cannot rule out every arbitrary rule someone could invent.

## Exercise 2: name the mirror axis at each step

Each row follows one of two fixed schedules: **vertical mirror, then horizontal mirror**, or **clockwise quarter-turn, then another clockwise quarter-turn**. Each mirror line passes through the centre of the current small board and stays aligned with the page. Determine the schedule from the completed rows, then choose the missing board.

```text
   c1    c2    c3
r1 1.. | ..1 | .3.
   ..2 | 2.. | 2..
   .3. | .3. | ..1
   ----+-----+----
   c1    c2    c3
r2 ..1 | 1.. | .2.
   3.. | ..3 | ..3
   .2. | .2. | 1..
   ----+-----+----
   c1    c2    c3
r3 .2. | .2. | ???
   ..3 | 3.. | ???
   1.. | ..1 | ???
```

```text
   A      B
   .2.    .2.
   ..3    3..
   1..    ..1
   C      D
   ..1    .3.
   3..    ..2
   .2.    1..
```

Text alternative: r1 = `1../..2/.3.`, `..1/2../.3.`, `.3./2../..1`; r2 = `..1/3../.2.`, `1../..3/.2.`, `.2./..3/1..`; r3 = `.2./..3/1..`, `.2./3../..1`, missing. Options: A `.2./..3/1..`; B `.2./3../..1`; C `..1/3../.2.`; D `.3./..2/1..`.

**Answer: C.** Row 1's first step swaps left and right: marker 1 moves across the top row, marker 2 across the middle row, and marker 3 stays on the vertical axis. A quarter-turn would move marker 2 to the bottom, so the turning schedule fails. The next step swaps top and bottom: marker 2 stays in the middle row while 1 and 3 change rows. Row 2 checks both operations with a different starting orientation.

For the missing board, start from r3c2. Reflecting across the horizontal centre line sends 1 from bottom right to top right, 2 from top centre to bottom centre, and leaves 3 at middle left. That is C. A mirrors vertically again and returns to r3c1. B skips the second operation. D turns r3c2 clockwise instead of reflecting it. Each fails a stated position, even though all retain three markers.

Two perpendicular reflections through the board centre produce the same final positions as a half-turn. That does **not** mean each intermediate reflection was a rotation: the middle board distinguishes the steps. Nor can these particular endpoints establish whether the vertical or horizontal reflection happened first; those two perpendicular reflections give the same endpoint in either order. Exercise 5 uses a different pair of operations for which order matters.

## Exercise 3: moving right and turning are separate changes

The small arrangement now sits inside a five-column frame. Read each outer row from left to right. Its three-column footprint starts at frame column 1, then 2, then 3; at the same time, the arrangement turns clockwise by 90° around its **own** centre on every step. Its three-row footprint stays fixed. Both changes are required. Choose the missing board.

```text
   c1      c2      c3
r1 1.... | ...1. | ...3.
   ..2.. | .3... | ..2..
   .3... | ..2.. | ....1
   ------+-------+------
   c1      c2      c3
r2 ..1.. | ..3.. | ...2.
   3.... | .2... | ....3
   .2... | ...1. | ..1..
   ------+-------+------
   c1      c2      c3
r3 .3... | ..2.. | ?????
   2.... | ...3. | ?????
   ..1.. | .1... | ?????
```

```text
   A      B
   .1...    ..1..
   ...2.    ....2
   ..3..    ...3.
   C      D
   ....1    ...2.
   ..2..    ....3
   ...3.    ..1..
```

Text alternative: r1 = `1..../..2../.3...`, `...1./.3.../..2..`, `...3./..2../....1`; r2 = `..1../3..../.2...`, `..3../.2.../...1.`, `...2./....3/..1..`; r3 = `.3.../2..../..1..`, `..2../...3./.1...`, missing. Options: A `.1.../...2./..3..`; B `..1../....2/...3.`; C `....1/..2../...3.`; D `...2./....3/..1..`.

**Answer: B.** Track the frame position and internal arrangement separately. All completed rows move the footprint from left to centre to right. Across row 2, the orientations advance from a quarter-turn to a half-turn to a three-quarter-turn; that checks the turning step independently of row 1.

In r3c2 the arrangement has turned three quarters of a full circle from the starting orientation. One more quarter-turn restores that orientation, now placed at the right. In B, marker 1 is at top/frame-column 3, 2 at middle/column 5, and 3 at bottom/column 4.

A has the right internal arrangement but leaves its footprint in the centre. C places the footprint correctly but reflects the internal arrangement. D moves the footprint right while leaving the three-quarter-turn orientation unchanged. Keeping the frame fixed while you compare the internal positions prevents movement from masquerading as a turn.

## Exercise 4: sometimes the picture cannot tell you

The two X marks on each board are identical. Compare four possible rules mapping the left board of each row to its right: no change, a half-turn, a vertical reflection, or a clockwise quarter-turn. Which conclusion is justified?

```text
   c1    c2
r1 ... | ...
   X.X | X.X
   ... | ...
   ----+----
   c1    c2
r2 .X. | ???
   ... | ???
   .X. | ???
```

Text alternative: r1 left and right are both `.../X.X/...`; r2 left is `.X./.../.X.`, and the right is missing. Conclusion options: A, only a half-turn is possible; B, only a vertical reflection is possible; C, no change, a half-turn and a vertical reflection remain indistinguishable; D, a clockwise quarter-turn explains the known pair.

**Answer: C.** In row 1, no change, a half-turn and a vertical reflection all leave the two identical X marks at the same visible positions. All three also predict `.X./.../.X.` for row 2's missing board. A and B each exclude alternatives without evidence. D fails the known row because a quarter-turn would turn the horizontal pair into a vertical pair.

There is one predicted visible board among the surviving candidates, but not one identifiable operation. Saying “the missing picture is clear” and saying “I proved it was a reflection” are different claims.

To make a more informative new exercise, label the left mark 1 and the right mark 2, then add a third marker 3 above the centre. No change keeps 1 on the left. A vertical reflection puts 1 on the right while 3 stays above. A half-turn puts 1 on the right while 3 moves below. The extra off-axis landmark distinguishes the latter two. You cannot add that mark to the original problem and treat it as evidence that was already given.

## Exercise 5: check the order on a new arrangement

Each left board becomes its right board by the **same** two operations. Compare two candidates: clockwise quarter-turn **then** vertical reflection, or vertical reflection **then** clockwise quarter-turn. Both act about the small board's centre, using a vertical mirror line fixed to the page. Use the completed pairs to select the order, then apply it to the new arrangement in row 3.

```text
   c1    c2
r1 1.. | 1..
   ..2 | ..3
   .3. | .2.
   ----+----
   c1    c2
r2 .3. | .2.
   2.. | 3..
   ..1 | ..1
   ----+----
   c1    c2
r3 .12 | ???
   ... | ???
   3.. | ???
```

```text
   A      B
   ..2    21.
   ..1    ...
   3..    ..3
   C      D
   3..    ..3
   ..1    1..
   ..2    2..
```

Text alternative: r1 = `1../..2/.3.` → `1../..3/.2.`; r2 = `.3./2../..1` → `.2./3../..1`; r3 = `.12/.../3..` → missing. Options: A `..2/..1/3..`; B `21./.../..3`; C `3../..1/..2`; D `..3/1../2..`.

**Answer: D.** In row 1, turning first sends marker 1 from top left to top right; reflecting then sends it back to top left, where the supplied result places it. Reversing the order would send it to bottom right instead, rejecting that candidate. Check row 2 as well: marker 2 moves from middle left to top centre, then stays on the mirror line at top centre, matching the supplied result.

For the new board, the clockwise turn sends 1 from top centre to middle right, 2 from top right to bottom right, and 3 from bottom left to top left. The following vertical reflection sends them to middle left, bottom left and top right respectively: D.

A reverses the two operations. B reflects without turning. C turns without reflecting. Thus “both operations are present” is not a complete rule: their order must also explain the known boards.

## Recheck a diagram with coordinates

Number a three-by-three board's rows and columns 1–3, starting at the top left. A clockwise quarter-turn maps `(row, column)` to `(column, 4 − row)`. A vertical reflection maps it to `(row, 4 − column)`, and a horizontal reflection to `(4 − row, column)`. Apply the mapping to every marker, preserving its identity.

For example, row 1/column 2 turns to row 2/column 3. Reflecting that result vertically gives row 2/column 1. Reverse the order and the point instead ends at row 2/column 3. This checks Exercise 5 without relying on how convincingly you can imagine turning the picture.

These are discrete-grid versions of the planar transformations described in [CIMT's matrices and transformations text, section 4](https://www.cimt.org.uk/ske/J4/Text.pdf). Its usual Cartesian coordinates point upward, while board rows here increase downward. The coordinate conventions must be converted before using a rotation formula. The original teaching boards here are not taken from that text.

## Use the method on fresh material

Cover an explanation and write three things: the centre or axis, where two named markers must go, and which known board checks the operation. Then test the third marker. If an option fails, identify a position that contradicts it. If several candidate operations still fit, state that uncertainty rather than inventing an exception.

For a fresh variation, replace Exercise 5's starting board with `..3/1../.2.`. Under clockwise turn then vertical reflection, predict `.1./..2/3..`. Check all three labels using coordinates. This is a new application of the stated rule; remembering D from the earlier question would not solve it. If a different task changes the axis or operation order, calculate again instead of reusing this output.

For a longer independent session, the [free FermatMind IQ test](/en/tests/iq-test-intelligence-quotient-assessment) offers 30 original visual-reasoning questions, with an estimated duration of about 20 minutes. The test and its results are free. This article's teaching exercises are separate material and do not supply its answer key. Learning a strategy here does not establish an increase in general intelligence. Use the [reasoning practice guide](/en/articles/iq-test-growth-guide) to plan observations; use the [score explanation](/en/articles/iq-test-score-and-limits-explained) to interpret the current result fields.

## Frequently asked questions

### How can I tell rotation from reflection in a matrix?

Specify the centre or mirror line, then track named landmarks. A rotation moves all points through the same angle; a reflection maps points across its line. Check more than one mark and verify a completed pair. Symmetric pictures may not distinguish the operations.

### Does a reflection always look different from a rotation?

No. A symmetric or insufficiently marked picture can have the same visible result under different operations. Exercise 4 gives a missing board that is predictable even though the operation is not uniquely identifiable.

### Should I rotate the whole matrix?

Only if the problem supports that relationship. Here each small board is transformed; the outer grid supplies examples and a missing board. Exercise 3 additionally moves the arrangement within its frame, which must be checked separately from its internal turn.

### Does the order of a turn and a mirror matter?

It can. A clockwise quarter-turn followed by a vertical reflection differs from the reversed order on the arrangements in Exercise 5. Check the stipulated centre, axis and completed examples rather than assuming all operations commute.
