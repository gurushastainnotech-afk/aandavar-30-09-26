<?php
ob_start();
include "pdf-header.php";
?>

<div style="padding:20px;">
  <h3>Selected Tile Preview</h3>
  <p>This design is generated based on your wall & floor selection.</p>
</div>

<?php
include "pdf-footer.php";
echo ob_get_clean();
