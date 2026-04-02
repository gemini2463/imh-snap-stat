import { useEffect, useRef } from "react";
import Chart from "chart.js/auto";

function PiechartUsersCPU() {
  const chartRef = useRef(null);
  const chartInstance = useRef(null);

  useEffect(() => {
    // Get the data from the global variable set by PHP
    const pieData = window.sysSnapPieDataCPU || [];

    // Prepare chart data
    const labels = pieData.map(item => item.user);
    const data = pieData.map(item => item.cpuScore);

    // Optional: colors
    const backgroundColors = [
      "#36a2eb", "#ff6384", "#ffce56", "#4bc0c0", "#9966ff", "#ff9f40",
      "#c9cbcf", "#ff4444", "#44ff44", "#4444ff", "#ff44ff", "#44ffff"
    ];
    const chartColors = labels.map((_, i) => backgroundColors[i % backgroundColors.length]);

    // Destroy old chart if it exists
    if (chartInstance.current) {
      chartInstance.current.destroy();
    }

    chartInstance.current = new Chart(chartRef.current, {
      type: "pie",
      data: {
        labels,
        datasets: [
          {
            label: "CPU Usage",
            data,
            backgroundColor: chartColors,
            hoverOffset: 8,
          },
        ],
      },
      options: {
        plugins: {
          legend: { position: "left" },
          title: { display: false, text: "CPU Usage by User" },
        },
        responsive: true,
      },
    });

    // Cleanup
    return () => chartInstance.current?.destroy();
  }, []);

  return (
    <div className="chart-container">
      <canvas ref={chartRef} />
    </div>
  );
}

// For local testing: provide dummy data if window.sysSnapPieDataCPU is not set
if (!window.sysSnapPieDataCPU) {
  window.sysSnapPieDataCPU = [
    { user: "alice", cpuScore: 30 },
    { user: "bob", cpuScore: 45 },
    { user: "carol", cpuScore: 25 },
  ];
}

export default PiechartUsersCPU;