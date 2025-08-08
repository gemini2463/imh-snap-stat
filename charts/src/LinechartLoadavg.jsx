import { useEffect, useRef } from "react";
import Chart from "chart.js/auto";

function LinechartLoadavg() {
  const chartRef = useRef(null);
  const chartInstance = useRef(null);

  useEffect(() => {
    const sarData = window.sysSnapSarLoadavgData || [];
    // Only keep those with valid Time and ldavg columns
    const filtered = sarData.filter(
      row => row["Time"] && row["ldavg-1"] !== undefined && row["ldavg-5"] !== undefined && row["ldavg-15"] !== undefined
    );

    const labels = filtered.map(row => row["Time"]);
    const dataset1 = filtered.map(row => parseFloat(row["ldavg-1"]));
    const dataset5 = filtered.map(row => parseFloat(row["ldavg-5"]));
    const dataset15 = filtered.map(row => parseFloat(row["ldavg-15"]));

    if (chartInstance.current) {
      chartInstance.current.destroy();
    }

    chartInstance.current = new Chart(chartRef.current, {
      type: "line",
      data: {
        labels,
        datasets: [
          {
            label: "ldavg-1",
            data: dataset1,
            borderColor: "#36a2eb",
            backgroundColor: "rgba(54,162,235,0.1)",
            tension: 0.2,
            fill: false,
          },
          {
            label: "ldavg-5",
            data: dataset5,
            borderColor: "#ff6384",
            backgroundColor: "rgba(255,99,132,0.1)",
            tension: 0.2,
            fill: false,
          },
          {
            label: "ldavg-15",
            data: dataset15,
            borderColor: "#ffce56",
            backgroundColor: "rgba(255,206,86,0.1)",
            tension: 0.2,
            fill: false,
          },
        ]
      },
      options: {
        responsive: true,
        plugins: {
          legend: { position: "top" },
          title: {
            display: true,
            text: "Load Average Over 24 Hours"
          }
        },
        scales: {
          x: {
            title: { display: true, text: "Time" },
            ticks: { autoSkip: true, maxTicksLimit: 15 }
          },
          y: {
            title: { display: true, text: "Load Average" },
            beginAtZero: true
          }
        }
      }
    });

    return () => chartInstance.current?.destroy();
  }, []);

  return (
    <div>
      <canvas ref={chartRef} />
    </div>
  );
}

// Dummy data for local testing
if (!window.sysSnapSarLoadavgData) {
  window.sysSnapSarLoadavgData = [
    { "Time": "00:00", "ldavg-1": "0.12", "ldavg-5": "0.10", "ldavg-15": "0.09" },
    { "Time": "01:00", "ldavg-1": "0.15", "ldavg-5": "0.13", "ldavg-15": "0.11" },
    { "Time": "02:00", "ldavg-1": "0.18", "ldavg-5": "0.16", "ldavg-15": "0.14" },
    { "Time": "03:00", "ldavg-1": "0.20", "ldavg-5": "0.19", "ldavg-15": "0.17" },
    { "Time": "04:00", "ldavg-1": "0.22", "ldavg-5": "0.21", "ldavg-15": "0.19" },
    { "Time": "05:00", "ldavg-1": "0.25", "ldavg-5": "0.23", "ldavg-15": "0.21" },
    { "Time": "06:00", "ldavg-1": "0.28", "ldavg-5": "0.26", "ldavg-15": "0.24" },
    { "Time": "07:00", "ldavg-1": "0.30", "ldavg-5": "0.28", "ldavg-15": "0.26" },
    { "Time": "08:00", "ldavg-1": "0.32", "ldavg-5": "0.30", "ldavg-15": "0.28" },
    { "Time": "09:00", "ldavg-1": "0.35", "ldavg-5": "0.33", "ldavg-15": "0.31" },
    { "Time": "10:00", "ldavg-1": "0.38", "ldavg-5": "0.36", "ldavg-15": "0.34" },
    { "Time": "11:00", "ldavg-1": "0.40", "ldavg-5": "0.38", "ldavg-15": "0.36" },
    { "Time": "12:00", "ldavg-1": "0.42", "ldavg-5": "0.40", "ldavg-15": "0.38" },
    { "Time": "13:00", "ldavg-1": "0.45", "ldavg-5": "0.43", "ldavg-15": "0.41" },
    { "Time": "14:00", "ldavg-1": "0.48", "ldavg-5": "0.46", "ldavg-15": "0.44" },
    { "Time": "15:00", "ldavg-1": "0.50", "ldavg-5": "0.48", "ldavg-15": "0.46" },
    { "Time": "16:00", "ldavg-1": "0.52", "ldavg-5": "0.50", "ldavg-15": "0.48" },
    { "Time": "17:00", "ldavg-1": "0.55", "ldavg-5": "0.53", "ldavg-15": "0.51" },
    { "Time": "18:00", "ldavg-1": "0.58", "ldavg-5": "0.56", "ldavg-15": "0.54" },
    { "Time": "19:00", "ldavg-1": "0.60", "ldavg-5": "0.58", "ldavg-15": "0.56" },
    { "Time": "20:00", "ldavg-1": "0.62", "ldavg-5": "0.60", "ldavg-15": "0.58" },
    { "Time": "21:00", "ldavg-1": "0.65", "ldavg-5": "0.63", "ldavg-15": "0.61" },
    { "Time": "22:00", "ldavg-1": "0.68", "ldavg-5": "0.66", "ldavg-15": "0.64" },
    { "Time": "23:00", "ldavg-1": "0.70", "ldavg-5": "0.68", "ldavg-15": "0.66" }
  ];
}

export default LinechartLoadavg;