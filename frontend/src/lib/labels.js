import { isAgencyRole } from '../auth/roles'

// Visible wording that differs for agency (client-facing) roles. Routes, API names and database
// terms stay the same: "employee" is still the internal term.
export function getAgencyLabels(role) {
  const isAgency = isAgencyRole(role)
  return {
    employeesHeld: isAgency ? 'My employees' : 'Employees held',
    myEmployees: isAgency ? 'My employees' : 'My employees',
    holdEmployee: isAgency ? 'Add employee' : 'Hold employee',
    selectEmployee: isAgency ? 'Choose employee' : 'Select employee',
    endAssignment: isAgency ? 'Release employee' : 'End assignment',
    dueDate: isAgency ? 'Delivery date' : 'Due date',
    specialist: isAgency ? 'Employee' : 'Employee',
    specialists: isAgency ? 'Employees' : 'Employees',
  }
}
