import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import PiechartUsersCPU from './PiechartUsersCPU'
import PiechartUsersMemory from './PiechartUsersMemory'
import LinechartLoadavg from './LinechartLoadavg'
import LinechartPaging from './LinechartPaging'

createRoot(document.getElementById('PiechartUsersCPU')).render(
  <StrictMode>
    <PiechartUsersCPU />
  </StrictMode>,
)

createRoot(document.getElementById('PiechartUsersMemory')).render(
  <StrictMode>
    <PiechartUsersMemory />
  </StrictMode>,
)

createRoot(document.getElementById('LinechartLoadavg')).render(
  <StrictMode>
    <LinechartLoadavg />
  </StrictMode>,
)

createRoot(document.getElementById('LinechartPaging')).render(
  <StrictMode>
    <LinechartPaging />
  </StrictMode>,
)