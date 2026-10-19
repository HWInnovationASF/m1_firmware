<?php
// Example PHP data (you can load from DB)
$labels = ["A", "B", "C", "D", "B", "C", "D", "B", "C", "D", "B", "C", "D", "B", "C", "D"];
$values = [3, 7, 5, 2, 7, 5, 2, 7, 5, 2, 7, 5, 2, 7, 5, 2];
$meter_sn = isset($_GET['meter_sn']) ? $_GET['meter_sn']: '';
$measure = isset($_GET['measure']) ? $_GET['measure']: '';
$charttype = isset($_GET['charttype']) ? $_GET['charttype']: '';
?>

<!DOCTYPE html>
<html>
<head>
  <title>Mini Column Chart</title>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    /* Make it mini */
    #myChart-<?php echo $meter_sn;?> {
      width: 100%;
      height: 150px;
    }
  </style>
</head>
<body>

<!-- <div style="width:100%;"> -->
  <canvas id="myChart-<?php echo $meter_sn;?>"></canvas>
<!-- </div> -->

<script>

const ctx = document.getElementById("myChart-<?php echo $meter_sn;?>").getContext("2d");

// Initial empty chart
let chart<?php echo $meter_sn;?> = new Chart(ctx, {
  type: "<?php echo $measure == "in_dca" ? "bar":"line" ;?>",
  data: {
    labels: [],
    datasets: [{
      data: [],
      backgroundColor: "#33ff00",
      borderColor: "#33ff00",
      pointRadius: 0,
      pointHoverRadius: 6,   // Show bigger point when hovered
      tension: 0.3           // Smooth curve (optional)
    }]
  },
  options: {
    responsive: false,
    interaction: {
      mode: 'nearest',   // Tooltip nearest point
      axis: 'x',
      intersect: false
    },
    plugins: { 
      legend: { display: false },
      tooltip: {
        enabled: true    // Tooltips are still active
      },
      title: {
        display: true,
        text: "<?php echo $measure;?>",
        font: {
          size: 14,
          weight: "normal"
        },
        color: "#000",
        align: "center" // "start" | "center" | "end"
      }
    },
    scales: { 
      x: { display: false }, y: { display: true}

    }
  }
});

// Helper function to format a Date object
function formatDateTime(date) {
    const Y = date.getFullYear();
    const M = String(date.getMonth() + 1).padStart(2, '0'); // months 0-11
    const D = String(date.getDate()).padStart(2, '0');
    const H = String(date.getHours()).padStart(2, '0');
    const I = String(date.getMinutes()).padStart(2, '0');
    const S = String(date.getSeconds()).padStart(2, '0');
    return `${Y}-${M}-${D} ${H}:${I}:${S}`;
}


    // Function to fetch and update chart
function updateChart() {
  fetch("./api_mdbiotx.php")
    .then(res => res.json())
    .then(data => {
        // console.log(data);
        <?php if($measure == "in_dca"){?>
        // Example: data_col_arr is array of [key, value]
        let labels2 = [  "in_dca_1",  "in_dca_2",  "in_dca_3",  "in_dca_4",  "in_dca_5",  "in_dca_6",  "in_dca_7",  "in_dca_8",  "in_dca_9",  "in_dca_10",  "in_dca_11",  "in_dca_12",  "in_dca_13","in_dca_14",  "in_dca_15",  "in_dca_16",  "in_dca_17",  "in_dca_18",  "in_dca_19",  "in_dca_20",  "in_dca_21",  "in_dca_22",  "in_dca_23",  "in_dca_24"]; 
        let mergedPairs = data.data['<?php echo $meter_sn;?>']; 
        let labels = [];
        let values = [];
        // labels = [...labels2,...labels];
        // console.log(dt);
        // console.log('<?php echo $meter_sn;?>');
        // chart<?php echo $meter_sn;?>.options.scales.y.max = 100;

        

        Object.entries(mergedPairs ?? {}).forEach(([key, value]) => {
            if (mergedPairs && typeof mergedPairs === 'object') { // Check if data_col_arr is not null/undefined and is an object
                if (/in_dca/.test(key)) {
                    // console.log("✅<?php echo $meter_sn;?>", key, "=", value);
                    labels.push(key);
                    values.push(value);

                }
            }
        });
        

        // labels.sort((a, b) => a[1] - b[1]);
        // values.sort((a, b) => a[1] - b[1]);
        

        // Update chart data
        chart<?php echo $meter_sn;?>.data.labels = labels;
        chart<?php echo $meter_sn;?>.data.datasets[0].data = values;
        chart<?php echo $meter_sn;?>.update();
      <?php }else{ ?>
        // console.log(data.data);
        const now = new Date();

        let labels = [];
        let values = [];
        Object.entries(data.data['<?php echo $meter_sn;?>'] ?? {}).forEach(([key, value]) => {
            if (data.data['<?php echo $meter_sn;?>'] && typeof data.data['<?php echo $meter_sn;?>'] === 'object') { // Check if data_col_arr is not null/undefined and is an object
                if (/<?php echo $measure;?>/.test(key)) {
                    // console.log(data.dt,"===✅<?php echo $meter_sn;?>", key, "=", value);
                    // labels.push(key);
                    // values.push({x:data.dt,y:value});
                    chart<?php echo $meter_sn;?>.data.labels.push(formatDateTime(new Date(now.getTime())));
                    chart<?php echo $meter_sn;?>.data.datasets[0].data.push({x:new Date(now.getTime()),y:value});
                    if(/p_out/.test(key)){
                      chart<?php echo $meter_sn;?>.options.scales.y.max = 100;
                      if(value < 0){
                        chart<?php echo $meter_sn;?>.options.scales.y.min = value;
                      }else{
                        chart<?php echo $meter_sn;?>.options.scales.y.min = 0;
                      }
                    }

                    chart<?php echo $meter_sn;?>.update();


                }
                
            }
        });
        // Update chart data
        // chart.data.labels.push(labels);
        // chart.data.datasets[0].data.push(values);
        // chart<?php echo $meter_sn;?>.update();
        
      <?php 
      } ?>
    })
    .catch(err => console.error(err));
}

// Initial load
updateChart();

// Update every 5 seconds
setInterval(updateChart, 5000);
</script>

</body>
</html>
