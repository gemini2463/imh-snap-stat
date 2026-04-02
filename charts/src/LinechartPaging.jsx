import { useEffect, useRef } from "react";
import Chart from "chart.js/auto";

function LinechartPaging() {
  const chartRef = useRef(null);
  const chartInstance = useRef(null);

  useEffect(() => {
    const sarData = window.sysSnapSarPagingData || [];
    // Only keep those with valid Time, pgpgin/s, and pgpgout/s
    const filtered = sarData.filter(
      row => row["Time"] && row["pgpgin/s"] !== undefined && row["pgpgout/s"] !== undefined
    );

    const labels = filtered.map(row => row["Time"]);
    const datasetIn = filtered.map(row => parseFloat(row["pgpgin/s"]));
    const datasetOut = filtered.map(row => parseFloat(row["pgpgout/s"]));

    if (chartInstance.current) {
      chartInstance.current.destroy();
    }

    chartInstance.current = new Chart(chartRef.current, {
      type: "line",
      data: {
        labels,
        datasets: [
          {
            label: "pgpgin/s",
            data: datasetIn,
            borderColor: "#4bc0c0",
            backgroundColor: "rgba(75,192,192,0.1)",
            tension: 0.2,
            fill: false,
          },
          {
            label: "pgpgout/s",
            data: datasetOut,
            borderColor: "#ff9f40",
            backgroundColor: "rgba(255,159,64,0.1)",
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
            text: "Paging Activity Over 24 Hours (KB/s)"
          }
        },
        scales: {
          x: {
            title: { display: true, text: "Time" },
            ticks: { autoSkip: true, maxTicksLimit: 15 }
          },
          y: {
            title: { display: true, text: "Kilobytes per Second" },
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
if (!window.sysSnapSarPagingData) {
  window.sysSnapSarPagingData = [
    { "Time": "00:00", "pgpgin/s": "10.2", "pgpgout/s": "5.3" },
    { "Time": "01:00", "pgpgin/s": "12.5", "pgpgout/s": "6.1" },
    { "Time": "02:00", "pgpgin/s": "15.0", "pgpgout/s": "7.2" },
    { "Time": "03:00", "pgpgin/s": "18.3", "pgpgout/s": "8.4" },
    { "Time": "04:00", "pgpgin/s": "20.1", "pgpgout/s": "9.5" },
    { "Time": "05:00", "pgpgin/s": "22.4", "pgpgout/s": "10.6" },
    { "Time": "06:00", "pgpgin/s": "25.7", "pgpgout/s": "11.8" },
    { "Time": "07:00", "pgpgin/s": "28.0", "pgpgout/s": "12.9" },
    { "Time": "08:00", "pgpgin/s": "30.2", "pgpgout/s": "14.0" },
    { "Time": "09:00", "pgpgin/s": "32.5", "pgpgout/s": "15.1" },
    { "Time": "10:00", "pgpgin/s": "35.8", "pgpgout/s": "16.3" },
    { "Time": "11:00", "pgpgin/s": "38.1", "pgpgout/s": "17.4" },
    { "Time": "12:00", "pgpgin/s": "40.4", "pgpgout/s": "18.5" },
    { "Time": "13:00", "pgpgin/s": "42.7", "pgpgout/s": "19.6" },
    { "Time": "14:00", "pgpgin/s": "45.0", "pgpgout/s": "20.8" },
    { "Time": "15:00", "pgpgin/s": "48.3", "pgpgout/s": "21.9" },
    { "Time": "16:00", "pgpgin/s": "50.6", "pgpgout/s": "23.0" },
    { "Time": "17:00", "pgpgin/s": "52.9", "pgpgout/s": "24.1" },
    { "Time": "18:00", "pgpgin/s": "55.2", "pgpgout/s": "25.3" },
    { "Time": "19:00", "pgpgin/s": "58.5", "pgpgout/s": "26.4" },
    { "Time": "20:00", "pgpgin/s": "60.8", "pgpgout/s": "27.5" },
    { "Time": "21:00", "pgpgin/s": "63.1", "pgpgout/s": "28.6" },
    { "Time": "22:00", "pgpgin/s": "65.4", "pgpgout/s": "29.8" },
    { "Time": "23:00", "pgpgin/s": "68.7", "pgpgout/s": "30.9" }
  ];
}

export default LinechartPaging;